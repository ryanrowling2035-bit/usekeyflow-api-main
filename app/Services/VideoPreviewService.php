<?php

namespace App\Services;

use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Video\X264;
use Illuminate\Support\Facades\Log;

class VideoPreviewService
{
    public function __construct(
        protected FileStorageService $fileStorage
    ) {}

    /**
     * Generate a 5-second preview clip from a video and store it on S3 (public).
     *
     * @param  string  $sourcePath  Absolute local path to the source video (the uploaded temp file)
     * @param  string  $previewName  Filename for the preview clip
     * @param  string  $baseFolder  Per-record base folder (e.g. "properties/AC-S-000001")
     * @return string|null The public S3 URL of the preview, or null on failure
     */
    public function generatePreview(string $sourcePath, string $previewName, string $baseFolder = 'properties'): ?string
    {
        $tempWatermarkPath = null;
        $previewAbsolutePath = null;

        try {
            if (! file_exists($sourcePath)) {
                return null;
            }

            // ffmpeg requires real local files for both input and output.
            $previewAbsolutePath = sys_get_temp_dir().'/'.$previewName;

            $ffmpeg = FFMpeg::create([
                'ffmpeg.binaries' => env('FFMPEG_BINARIES', '/usr/bin/ffmpeg'),
                'ffprobe.binaries' => env('FFPROBE_BINARIES', '/usr/bin/ffprobe'),
                'timeout' => 120,
                'ffmpeg.threads' => 12,
            ]);

            $video = $ffmpeg->open($sourcePath);

            $video->filters()->clip(
                TimeCode::fromSeconds(0),
                TimeCode::fromSeconds(5)
            );

            // Apply watermark scaled to 30% of video width, centered
            $videoWidth = $video->getStreams()->videos()->first()?->getDimensions()->getWidth();
            if ($videoWidth) {
                $watermark = getScaledWatermarkImage((int) ($videoWidth * 0.3));
                if ($watermark) {
                    $tempWatermarkPath = sys_get_temp_dir().'/wm_'.uniqid().'.png';
                    $watermark->toPng()->save($tempWatermarkPath);
                    $video->filters()->watermark($tempWatermarkPath, [
                        'position' => 'absolute',
                        'x' => '(main_w-overlay_w)/2',
                        'y' => '(main_h-overlay_h)/2',
                    ]);
                }
            }

            $format = new X264;
            $format->setAudioCodec('aac');

            $video->save($format, $previewAbsolutePath);

            return $this->fileStorage->putPublic(
                rtrim($baseFolder, '/').'/compressed/'.$previewName,
                file_get_contents($previewAbsolutePath)
            );
        } catch (\Exception $e) {
            Log::error("VideoPreviewService failed: {$e->getMessage()}");

            return null;
        } finally {
            if ($tempWatermarkPath && file_exists($tempWatermarkPath)) {
                unlink($tempWatermarkPath);
            }
            if ($previewAbsolutePath && file_exists($previewAbsolutePath)) {
                unlink($previewAbsolutePath);
            }
        }
    }
}
