<?php

namespace App\Services\Notices;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use UnexpectedValueException;

/**
 * A vendor's feed of news that may announce security releases ahead of time (handover 5.2, "Thông báo sớm").
 */
interface NoticeFeed
{
    /**
     * Every entry the feed lists now.
     *
     * @return list<NoticeEntry>
     *
     * @throws ConnectionException
     * @throws RequestException
     * @throws UnexpectedValueException When the response is not an Atom or RSS feed.
     */
    public function entries(): array;
}
