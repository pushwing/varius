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
    private function injectOrientation(string $jpegPath, int $orientation): string
    {
        $raw = (string) file_get_contents($jpegPath);
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

    private function makeJpegFixtureWithOrientation(int $width, int $height, int $orientation): string
    {
        return $this->injectOrientation($this->makeJpegFixture($width, $height), $orientation);
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

    /**
     * 좌상단 사분면만 빨간색인 width x height JPEG 을 만들고 Orientation 태그를 붙인다.
     * 회전 "방향"까지 검증하기 위한 픽스처(어느 코너로 빨강이 이동했는지 확인).
     */
    private function makeCornerMarkedJpegWithOrientation(int $width, int $height, int $orientation): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 0, 0, 255));
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, intdiv($height, 2) - 1, (int) imagecolorallocate($image, 255, 0, 0));

        $path = tempnam(sys_get_temp_dir(), 'iter_src_corner_') . '.jpg';
        imagejpeg($image, $path, 100);
        $this->tempFiles[] = $path;

        return $this->injectOrientation($path, $orientation);
    }

    /**
     * 썸네일의 사분면 중심을 샘플링해 빨간 사분면 위치를 반환한다(TL/TR/BL/BR).
     */
    private function redQuadrantOf(string $path): string
    {
        $image = imagecreatefromjpeg($path);
        $this->assertNotFalse($image);
        $width = imagesx($image);
        $height = imagesy($image);

        $isRed = function (int $x, int $y) use ($image): bool {
            $rgb = imagecolorat($image, $x, $y);

            return (($rgb >> 16) & 0xFF) > (($rgb) & 0xFF); // R 이 B 보다 우세하면 빨강.
        };

        $qx = intdiv($width, 4);
        $qy = intdiv($height, 4);
        $corners = [
            'TL' => [$qx, $qy],
            'TR' => [$width - $qx, $qy],
            'BL' => [$qx, $height - $qy],
            'BR' => [$width - $qx, $height - $qy],
        ];
        foreach ($corners as $name => [$x, $y]) {
            if ($isRed($x, $y)) {
                return $name;
            }
        }

        return 'NONE';
    }

    public function testHonorsExifOrientation6ByRotatingClockwise(): void
    {
        // 태그 6 = "시계방향 90도 돌려야 올바름" — 가로(60x40) 원시 픽셀이
        // 세로(40x60) 썸네일이 되고, 좌상단 빨강은 우상단으로 이동해야 한다.
        // (이 보정이 없으면 구글포토에선 똑바로 보이는 사진이 썸네일에서 좌로 돌아간다.)
        $source = $this->makeCornerMarkedJpegWithOrientation(60, 40, 6);

        $path = (new GdThumbnailGenerator($this->outputDir))->generate($source, 'media-o6', 1);

        $this->assertNotNull($path);
        [$width, $height] = getimagesize($path);
        $this->assertSame(40, $width);
        $this->assertSame(60, $height);
        $this->assertSame('TR', $this->redQuadrantOf($path));
    }

    public function testHonorsExifOrientation8ByRotatingCounterClockwise(): void
    {
        $source = $this->makeCornerMarkedJpegWithOrientation(60, 40, 8);

        $path = (new GdThumbnailGenerator($this->outputDir))->generate($source, 'media-o8', 1);

        $this->assertNotNull($path);
        [$width, $height] = getimagesize($path);
        $this->assertSame(40, $width);
        $this->assertSame(60, $height);
        $this->assertSame('BL', $this->redQuadrantOf($path));
    }

    public function testHonorsExifOrientation3ByRotating180(): void
    {
        $source = $this->makeCornerMarkedJpegWithOrientation(60, 40, 3);

        $path = (new GdThumbnailGenerator($this->outputDir))->generate($source, 'media-o3', 1);

        $this->assertNotNull($path);
        [$width, $height] = getimagesize($path);
        $this->assertSame(60, $width);
        $this->assertSame(40, $height);
        $this->assertSame('BR', $this->redQuadrantOf($path));
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

    public function testReturnsNullWhenSourceExceedsPixelCap(): void
    {
        // 픽셀 상한을 넘는 초대형 이미지는 디코딩하지 않는다(OOM 예방). 상한을 작게 주입해
        // 100x100(10,000px)이 5,000px 상한을 넘는 상황을 재현한다.
        $source = $this->makeJpegFixture(100, 100);

        $result = (new GdThumbnailGenerator($this->outputDir, 5_000))->generate($source, 'huge', 1);

        $this->assertNull($result);
        $this->assertSame([], glob($this->outputDir . '/1/*') ?: []);
    }

    public function testGeneratesThumbnailWhenSourceIsWithinPixelCap(): void
    {
        $source = $this->makeJpegFixture(100, 100); // 10,000px

        $result = (new GdThumbnailGenerator($this->outputDir, 20_000))->generate($source, 'ok', 1);

        $this->assertNotNull($result);
        $this->assertFileExists($result);
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
