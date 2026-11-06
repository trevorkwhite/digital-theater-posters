<?php

namespace App\Console\Commands;

use App\Display\DisplayStateService;
use Illuminate\Console\Command;

class DisplayAutoRestore extends Command
{
    protected $signature = 'display:auto-restore';

    protected $description = 'Return the display to normal if a dim/black state has expired (POSTER_AUTO_RESTORE_MINUTES)';

    public function handle(DisplayStateService $display): int
    {
        if ($display->restoreIfExpired()) {
            $this->info('Display state expired: restored to normal.');
        } else {
            $this->line('Nothing to restore.', verbosity: 'v');
        }

        return self::SUCCESS;
    }
}
