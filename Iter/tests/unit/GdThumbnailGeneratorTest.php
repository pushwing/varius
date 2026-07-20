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
        if (is_dir($this->outputDir)) {
            array_map('unlink', glob($this->outputDir . '/*') ?: []);
            rmdir($this->outputDir);
        }
        parent::tearDown();
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

    public function testGeneratesThumbnailWithTargetWidthPreservingAspectRatio(): void
    {
        $source = $this->makeJpegFixture(1200, 600); // 2:1

        $path = (new GdThumbnailGenerator($this->outputDir))->generate($source, 'media-1');

        $this->assertNotNull($path);
        $this->assertFileExists($path);

        [$width, $height] = getimagesize($path);
        $this->assertSame(300, $width);
        $this->assertSame(150, $height); // 2:1 비율 유지
    }

    public function testDoesNotUpscaleImagesNarrowerThanTargetWidth(): void
    {
        $source = $this->makeJpegFixture(150, 100);

        $path = (new GdThumbnailGenerator($this->outputDir))->generate($source, 'media-2');

        $this->assertNotNull($path);
        [$width] = getimagesize($path);
        $this->assertSame(150, $width); // 원본보다 확대하지 않는다.
    }

    public function testReturnsNullForNonImageFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'iter_notimg_');
        file_put_contents($path, 'not an image');
        $this->tempFiles[] = $path;

        $result = (new GdThumbnailGenerator($this->outputDir))->generate($path, 'media-3');

        $this->assertNull($result);
    }

    public function testFileNameIsDerivedFromMediaItemId(): void
    {
        $source = $this->makeJpegFixture(400, 400);

        $path = (new GdThumbnailGenerator($this->outputDir))->generate($source, 'weird/id:1');

        $this->assertNotNull($path);
        // 예약문자가 섞인 media_item_id 도 안전한 파일명으로 정리돼야 한다.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_.-]+$/', basename($path));
    }
}
