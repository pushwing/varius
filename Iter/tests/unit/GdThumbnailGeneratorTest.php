<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ingest\GdThumbnailGenerator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class GdThumbnailGeneratorTest extends CIUnitTestCase
{
    /** @var list<string> 테스트가 만든 임시 파일(정리 대상) */
    private array $tempFiles = [];

    private string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outputDir = sys_get_temp_dir() . '/iter_thumb_test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->removeDirectory($this->outputDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    /**
     * width x height 크기의 JPEG 픽스처 파일을 만든다.
     */
    private function makeJpegFixture(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $path = tempnam(sys_get_temp_dir(), 'iter_src_') . '.jpg';
        imagejpeg($image, $path);

        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * GD 는 JPEG 를 쓸 때 EXIF 를 남기지 않으므로, EXIF Orientation 태그 하나만 담은
     * 최소 APP1 세그먼트를 직접 만들어 SOI 뒤에 끼워넣는다(TIFF/EXIF 스펙 최소 구현).
     */
    private function makeJpegFixtureWithOrientation(int $width, int $height, int $orientation): string
    {
        $raw = (string) file_get_contents($this->makeJpegFixture($width, $height));
        $withoutSoi = substr($raw, 2); // GD 가 붙인 SOI(FF D8) 제거 — 우리가 다시 앞에 붙인다.

        $tiffHeader = 'II' . pack('v', 0x002A) . pack('V', 8);
        $ifdEntry = pack('v', 0x0112) . pack('v', 3) . pack('V', 1) . pack('v', $orientation) . pack('v', 0);
        $ifd0 = pack('v', 1) . $ifdEntry . pack('V', 0);
        $exifPayload = "Exif\0\0" . $tiffHeader . $ifd0;
        $app1 = "\xFF\xE1" . pack('n', strlen($exifPayload) + 2) . $exifPayload;

        $path = tempnam(sys_get_temp_dir(), 'iter_src_exif_') . '.jpg';
        file_put_contents($path, "\xFF\xD8" . $app1 . $withoutSoi);
        $this->tempFiles[] = $path;

        return $path;
    }

    public function testGeneratesThumbnailWithTargetWidthPreservingAspectRatio(): void
    {
        $source = $this->makeJpegFixture(1200, 600); // 2:1

        $path = (new GdThumbnailGenerator($this->outputDir))->generate($source, 'media-1', 1);

        $this->assertNotNull($path);
        $this->assertFileExists($path);

        [$width, $height] = getimagesize($path);
        $this->assertSame(300, $width);
        $this->assertSame(150, $height); // 2:1 비율 유지
    }

    public function testDoesNotUpscaleImagesNarrowerThanTargetWidth(): void
    {
        $source = $this->makeJpegFixture(150, 100);

        $path = (new GdThumbnailGenerator($this->outputDir))->generate($source, 'media-2', 1);

        $this->assertNotNull($path);
        [$width] = getimagesize($path);
        $this->assertSame(150, $width); // 원본보다 확대하지 않는다.
    }

    public function testReturnsNullForNonImageFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'iter_notimg_');
        file_put_contents($path, 'not an image');
        $this->tempFiles[] = $path;

        $result = (new GdThumbnailGenerator($this->outputDir))->generate($path, 'media-3', 1);

        $this->assertNull($result);
    }

    public function testDoesNotRotateBasedOnExifOrientationTag(): void
    {
        // Google Takeout 으로 재내보낸 사진은 픽셀 자체가 이미 올바른 방향으로
        // 구워져 있는데 Orientation 태그만 예전 값으로 남아있는 경우가 있다(실사용
        // 리포트로 확인 — 가로 사진이 태그 기반 보정 후 전부 세로로 바뀜). 그래서
        // Orientation 태그를 신뢰해 추가 회전을 적용하지 않는다 — 디코딩된 픽셀
        // 그대로 리사이즈만 한다.
        $source = $this->makeJpegFixtureWithOrientation(40, 60, 6);

        $path = (new GdThumbnailGenerator($this->outputDir))->generate($source, 'media-o6', 1);

        $this->assertNotNull($path);
        [$width, $height] = getimagesize($path);
        $this->assertSame(40, $width);
        $this->assertSame(60, $height);
    }

    public function testOrientation1DoesNotChangeDimensions(): void
    {
        $source = $this->makeJpegFixtureWithOrientation(40, 60, 1);

        $path = (new GdThumbnailGenerator($this->outputDir))->generate($source, 'media-o1', 1);

        $this->assertNotNull($path);
        [$width, $height] = getimagesize($path);
        $this->assertSame(40, $width);
        $this->assertSame(60, $height);
    }

    public function testFileNameIsDerivedFromMediaItemId(): void
    {
        $source = $this->makeJpegFixture(400, 400);

        $path = (new GdThumbnailGenerator($this->outputDir))->generate($source, 'weird/id:1', 1);

        $this->assertNotNull($path);
        // 예약문자가 섞인 media_item_id 도 안전한 파일명으로 정리돼야 한다.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_.-]+$/', basename($path));
    }

    public function testOutputPathIsNamespacedByUserId(): void
    {
        $source = $this->makeJpegFixture(400, 400);

        $path = (new GdThumbnailGenerator($this->outputDir))->generate($source, 'IMG_0001.JPG', 7);

        $this->assertNotNull($path);
        $this->assertSame($this->outputDir . '/7/IMG_0001.JPG.jpg', $path);
    }

    public function testDifferentUsersWithSameSourceItemIdDoNotOverwriteEachOther(): void
    {
        // 흔한 카메라 기본 파일명(IMG_0001.JPG)이 사용자 간 충돌하는 상황 재현.
        $sourceA = $this->makeJpegFixture(400, 400);
        $sourceB = $this->makeJpegFixture(200, 200);

        $generator = new GdThumbnailGenerator($this->outputDir);
        $pathA = $generator->generate($sourceA, 'IMG_0001.JPG', 1);
        $pathB = $generator->generate($sourceB, 'IMG_0001.JPG', 2);

        $this->assertNotNull($pathA);
        $this->assertNotNull($pathB);
        $this->assertNotSame($pathA, $pathB);

        // 사용자 A의 썸네일이 사용자 B의 업로드로 덮어써지지 않아야 한다.
        [$widthA] = getimagesize($pathA);
        [$widthB] = getimagesize($pathB);
        $this->assertSame(300, $widthA);
        $this->assertSame(200, $widthB);
    }
}
