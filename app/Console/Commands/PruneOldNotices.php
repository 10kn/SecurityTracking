<?php

namespace App\Console\Commands;

use App\Actions\PruneOldNotices as PruneOldNoticesAction;
use App\Enums\Technology;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('psm:prune-notices {--days= : Delete news older than this many days, PSM_NOTICE_COLLECT_DAYS by default} {--pretend : Only count, delete nothing} {--force : Delete without asking}')]
#[Description('Delete the vendor news stored before the collection window, with the incidents only it tells of')]
class PruneOldNotices extends Command
{
    /**
     * Counts first, then deletes once the admin confirms (see PruneOldNotices): deleted news cannot be brought back,
     * as the feeds no longer list it or it is outside the window again on the next poll. The window is never shorter
     * than `psm.notices.relevant_days`, as for the polls.
     */
    public function handle(PruneOldNoticesAction $prune): int
    {
        $days = max((int) ($this->option('days') ?? config('psm.notices.collect_days')), config('psm.notices.relevant_days'));
        $before = now()->subDays($days);

        $counts = collect(Technology::cases())->mapWithKeys(fn (Technology $technology): array => [$technology->value => $prune->handle($technology, $before, pretend: true)]);
        $this->table(
            [__('Công nghệ'), __('Sự kiện sẽ xoá'), __('Tin sẽ xoá'), __('Sự kiện giữ lại vì đã gửi hoặc đánh giá')],
            $counts->map(fn (array $count, string $technology): array => [Technology::from($technology)->getLabel(), $count['incidents'], $count['notices'], $count['kept']])->values()->all(),
        );

        if ($counts->sum('notices') === 0) {
            $this->components->info(__('Không có tin nào đăng trước :date.', ['date' => $before->toDateString()]));

            return self::SUCCESS;
        }

        if ($this->option('pretend') || (! $this->option('force') && ! $this->confirm(__('Xoá vĩnh viễn các tin đăng trước :date? Không khôi phục được.', ['date' => $before->toDateString()])))) {
            return self::SUCCESS;
        }

        $deleted = collect(Technology::cases())->map(fn (Technology $technology): array => $prune->handle($technology, $before));
        $this->components->info(__('Đã xoá :incidents sự kiện và :notices tin.', ['incidents' => $deleted->sum('incidents'), 'notices' => $deleted->sum('notices')]));

        return self::SUCCESS;
    }
}
