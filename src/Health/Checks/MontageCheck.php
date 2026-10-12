<?php
declare(strict_types=1);

namespace Sonoquill\Health\Checks;

use Sonoquill\Audio\AudioEngine;
use Sonoquill\Audio\MusicBed;
use Sonoquill\Health\CheckInterface;
use Sonoquill\Health\Result;
use Sonoquill\Settings\Options;

/**
 * Which assembly path is active, and whether it can do what is asked of it.
 */
final class MontageCheck implements CheckInterface
{
    public function id(): string
    {
        return 'montage';
    }

    public function label(): string
    {
        return __('Assembly', 'sonoquill');
    }

    public function run(): Result
    {
        $reason = AudioEngine::reason();

        if (AudioEngine::mode() === AudioEngine::MODE_FFMPEG) {
            return AudioEngine::ffmpegAvailable()
                ? Result::ok(__('ffmpeg', 'sonoquill'), $reason)
                : Result::fail(__('ffmpeg is set but not available.', 'sonoquill'), $reason);
        }

        $music = MusicBed::active();
        $wantsMusic = $music['opener'] !== null || $music['outro'] !== null || $music['trenner'] !== [];
        if ($wantsMusic && Options::directProduction()) {
            return Result::warn(
                __('PHP without Auphonic — opener, bridges and outro are left out. Install ffmpeg to mix them in directly.', 'sonoquill'),
                $reason
            );
        }
        if ($wantsMusic && Options::get('auphonic_preset') === '') {
            return Result::warn(
                __('PHP — but opener, bridges and outro need Auphonic, and no Auphonic preset is set.', 'sonoquill'),
                $reason
            );
        }

        return Result::ok(__('PHP, music via Auphonic', 'sonoquill'), $reason);
    }
}
