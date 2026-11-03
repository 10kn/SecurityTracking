<?php

namespace App\Actions;

use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Services\Notices\NoticeClassifier;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Localizable;
use Throwable;

/**
 * Tells the admin space as soon as the notice classifier fails, with the error and what to check, and again once it
 * classifies an entry after that. One alert per outage: the outage lasts from the first error to the next success,
 * whatever the number of entries or polls in between. psm:check-sources still tells about each pre-notice that stays
 * unclassified (see CheckSourceHeartbeats).
 */
class ReportClassifierOutage
{
    use Localizable;

    /**
     * When the current outage started, as a timestamp.
     */
    public const string CACHE_KEY = 'notice-classifier:outage-since';

    public function __construct(private SendAdminAlert $sendAdminAlert, private NoticeClassifier $classifier) {}

    public function failed(Throwable $exception): void
    {
        $since = now()->getTimestamp();

        if (! Cache::add(self::CACHE_KEY, $since)) {
            return;
        }

        $this->withLocale(config('psm.alerts.locale'), fn () => $this->sendAdminAlert->handle(
            "classifier-failing:{$since}",
            __('AI phân loại tin bảo mật đang lỗi'),
            [
                __('Model: :model', ['model' => $this->classifier->name()]),
                __('Lỗi: :error', ['error' => $this->error($exception)]),
                $this->advice($exception),
                __('Trong lúc này tin được xử lý theo từ khoá và điểm CVSS; tin chưa phân loại được thử lại ở mỗi lần quét.'),
            ],
        ));
    }

    public function recovered(): void
    {
        $since = Cache::pull(self::CACHE_KEY);

        if ($since === null) {
            return;
        }

        $this->withLocale(config('psm.alerts.locale'), fn () => $this->sendAdminAlert->handle(
            "classifier-recovered:{$since}",
            __('AI phân loại tin bảo mật đã hoạt động lại'),
            [__('Lỗi từ :time tới :now.', ['time' => LocalTime::format(CarbonImmutable::createFromTimestamp($since)), 'now' => LocalTime::format(now())])],
        ));
    }

    /**
     * The API's own message when it gave one, e.g. "HTTP 400: Your credit balance is too low…".
     */
    private function error(Throwable $exception): string
    {
        $message = $exception instanceof APIStatusException
            ? "HTTP {$exception->status}: ".(data_get($exception->body, 'error.message') ?? data_get($exception->body, 'error.type') ?? '—')
            : class_basename($exception).': '.$exception->getMessage();

        return Str::limit(Str::squish($message), 300);
    }

    private function advice(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof AuthenticationException, $exception instanceof PermissionDeniedException => __('API key không hợp lệ hoặc không có quyền. Kiểm tra ANTHROPIC_API_KEY.'),
            $exception instanceof BadRequestException => __('Anthropic từ chối yêu cầu, thường do hết credit. Kiểm tra số dư của tài khoản Anthropic.'),
            $exception instanceof NotFoundException => __('Không tìm thấy model. Kiểm tra PSM_NOTICE_CLASSIFIER_MODEL.'),
            $exception instanceof RateLimitException, $exception instanceof InternalServerException, $exception instanceof APIConnectionException => __('Lỗi tạm thời từ Anthropic hoặc mạng; thường tự hết ở lần quét sau.'),
            default => __('Kiểm tra log lỗi.'),
        };
    }
}
