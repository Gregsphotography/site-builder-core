<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Publishing;

use Illuminate\Console\Command;
use Throwable;

final class PublishDueCommand extends Command
{
    protected $signature = 'site-builder:publish-due';

    protected $description = 'Publish approved page versions whose scheduled time has arrived';

    public function handle(PageReviews $reviews): int
    {
        $failures = 0;
        if (PageReview::available()) {
            foreach (PageReview::query()->where('status', 'pending')->where('expires_at', '<=', now()->timestamp)->lazyById(100) as $review) {
                $reviews->expire($review);
            }
            foreach (PageReview::query()->where('status', 'scheduled')->where('scheduled_for', '<=', now()->timestamp)->lazyById(100) as $review) {
                try {
                    if (! $reviews->runDue($review)) {
                        $this->components->error('Could not release page '.$review->page_id.'. See its review history.');
                        $failures++;
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    $this->components->error('Page '.$review->page_id.' remains scheduled for retry.');
                    $failures++;
                }
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
