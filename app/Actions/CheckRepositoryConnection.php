<?php

namespace App\Actions;

use App\Models\RepositoryConnection;
use App\Services\Repositories\CredentialsRejectedException;
use App\Services\Repositories\GitHubTokenInspector;
use App\Support\LocalTime;
use Throwable;

/**
 * Asks GitHub about a repository connection's token and keeps what it says: the account, the scopes of a classic
 * token and the expiry date. The admin space hears once per token when GitHub turns it down, and once per expiry date
 * when it expires within RepositoryConnection::EXPIRY_WARNING_DAYS; a new token alerts again.
 */
class CheckRepositoryConnection
{
    public function __construct(private GitHubTokenInspector $tokenInspector, private SendAdminAlert $sendAdminAlert) {}

    /**
     * @return bool Whether GitHub accepted the token.
     *
     * @throws Throwable When GitHub could not be reached; nothing is recorded.
     */
    public function handle(RepositoryConnection $connection): bool
    {
        try {
            $account = $this->tokenInspector->inspect((string) $connection->token);
        } catch (CredentialsRejectedException $exception) {
            $connection->update(['last_checked_at' => now(), 'last_error' => $exception->getMessage()]);
            $this->alert($connection, 'rejected', __('GitHub không nhận token của kết nối :connection', ['connection' => $connection->name]), [
                $exception->getMessage(),
                __('Service đọc repository qua kết nối này giữ lockfile đã lấy trước đó cho tới khi token được thay.'),
            ]);

            return false;
        }

        $connection->update([
            'account_login' => $account['login'],
            'scopes' => $account['scopes'],
            'expires_at' => $account['expires_at'],
            'last_checked_at' => now(),
            'last_error' => null,
        ]);

        if ($connection->isExpiringSoon()) {
            $this->alert($connection, 'expiry:'.$connection->expires_at->toDateString(), __('Token của kết nối :connection sắp hết hạn', ['connection' => $connection->name]), [
                __('Hết hạn lúc :time.', ['time' => LocalTime::format($connection->expires_at)]),
                __('Nhờ account :account tạo token mới rồi bấm "Thay token" trên trang kết nối.', ['account' => $connection->account_login]),
            ]);
        }

        return true;
    }

    /**
     * @param  list<string>  $lines
     */
    private function alert(RepositoryConnection $connection, string $reason, string $title, array $lines): void
    {
        // One key per token: a replaced token is alerted about again.
        $tokenKey = substr(hash('sha256', (string) $connection->token), 0, 12);

        $this->sendAdminAlert->handle(
            "repository-connection:{$connection->id}:{$tokenKey}:{$reason}",
            $title,
            [...$lines, __('Account: :account.', ['account' => $connection->account_login ?? '?'])],
            [__('Mở kết nối') => route('admin.repository-connections.edit', $connection)],
        );
    }
}
