<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 쿼터 모니터링 대상이 되는 Google API 구분.
 */
enum GoogleApiName: string
{
    case Picker = 'picker';
    case MediaDownload = 'media_download';
}
