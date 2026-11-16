import { ExternalLinkIcon, NewspaperIcon, RssIcon, XIcon } from 'lucide-react';
import { useState } from 'react';

import { Popover, PopoverAnchor, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { VendorNewsSource } from './workflow-model';

export function VendorSources({
    sources,
    title,
    subtitle,
    frequency,
    onInspect,
    onHover,
}: {
    sources: VendorNewsSource[];
    title: string;
    subtitle: string;
    frequency: string;
    onInspect: () => void;
    onHover: (hovering: boolean) => void;
}) {
    const t = useTranslation();
    const [isOpen, setIsOpen] = useState(false);
    const [selectedKey, setSelectedKey] = useState(sources[0].key);
    const selected = sources.find((source) => source.key === selectedKey) ?? sources[0];
    const descriptions: Record<VendorNewsSource['key'], string> = {
        php: t('Feed Atom trên php.net gồm tin phát hành, tin cộng đồng và thông báo bảo mật. AI phân loại để nhận diện tin bảo mật.'),
        nodejs: t('Feed RSS của mục Vulnerabilities. PSM đọc thêm nội dung bài viết Node.js được liên kết để lấy chi tiết và các cập nhật.'),
        ruby: t('Feed RSS của ruby-lang.org gồm tin phát hành và security advisory. AI phân loại để nhận diện tin bảo mật.'),
    };

    function inspect(key: VendorNewsSource['key']) {
        setSelectedKey(key);
        setIsOpen(true);
        onInspect();
    }

    return (
        <Popover
            open={isOpen}
            onOpenChange={(open) => {
                setIsOpen(open);
                if (open) onInspect();
            }}
        >
            <PopoverAnchor asChild>
                <div className="guide-node guide-source" onMouseEnter={() => onHover(true)} onMouseLeave={() => onHover(false)}>
                    <PopoverTrigger asChild>
                        <button
                            type="button"
                            className="flex w-full flex-col items-center outline-none focus-visible:underline"
                            aria-label={t('Giải thích: :label', { label: title })}
                        >
                            <span className="guide-node-label">{title}</span>
                            <span className="guide-node-sub">{subtitle}</span>
                        </button>
                    </PopoverTrigger>
                    <div className="guide-feeds">
                        {sources.map((source) => (
                            <button
                                key={source.key}
                                type="button"
                                className="guide-feed"
                                onClick={() => inspect(source.key)}
                                aria-label={t('Xem nguồn tin :name', { name: source.name })}
                                aria-haspopup="dialog"
                                aria-expanded={isOpen && selectedKey === source.key}
                            >
                                <span>{source.name}</span>
                                <span className="guide-source-circle">
                                    <NewspaperIcon aria-hidden />
                                </span>
                            </button>
                        ))}
                    </div>
                </div>
            </PopoverAnchor>
            <PopoverContent
                side="top"
                align="center"
                sideOffset={12}
                collisionPadding={12}
                className="max-h-[min(600px,75dvh,var(--radix-popover-content-available-height))] w-[400px] max-w-[calc(100vw-24px)] overflow-y-auto p-4"
                aria-label={t('Nguồn tin :name', { name: selected.name })}
            >
                <div className="flex items-start justify-between gap-3">
                    <h3 className="font-semibold">{t('Nguồn tin :name', { name: selected.name })}</h3>
                    <button type="button" className="rounded p-1 text-faint hover:bg-surface-2" onClick={() => setIsOpen(false)} aria-label={t('Đóng giải thích')}>
                        <XIcon className="size-4" aria-hidden />
                    </button>
                </div>
                <div className="mt-3 flex flex-wrap gap-2" role="group" aria-label={t('Chọn nguồn tin')}>
                    {sources.map((source) => (
                        <button
                            key={source.key}
                            type="button"
                            aria-pressed={source.key === selected.key}
                            onClick={() => setSelectedKey(source.key)}
                            className={cn(
                                'rounded border border-border px-3 py-1.5 text-sm text-muted-foreground hover:bg-surface-2',
                                selected.key === source.key && 'bg-primary-soft font-medium text-link',
                            )}
                        >
                            {source.name}
                        </button>
                    ))}
                </div>
                <p className="mt-4 text-sm leading-relaxed text-muted-foreground">{descriptions[selected.key]}</p>
                <dl className="mt-4 grid gap-4 text-sm">
                    <div>
                        <dt className="mb-1 text-xs text-faint">{t('Trang tin tức')}</dt>
                        <dd>
                            <a href={selected.website_url} target="_blank" rel="noopener noreferrer" className="inline-flex items-start gap-2 break-all text-link hover:underline">
                                <span>{selected.website_url}</span>
                                <ExternalLinkIcon className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                            </a>
                        </dd>
                    </div>
                    <div>
                        <dt className="mb-1 flex items-center gap-1.5 text-xs text-faint">
                            <RssIcon className="size-3" aria-hidden />
                            {t('Feed hệ thống đọc')}
                        </dt>
                        <dd>
                            <a href={selected.feed_url} target="_blank" rel="noopener noreferrer" className="break-all font-mono text-xs text-link hover:underline">
                                {selected.feed_url}
                            </a>
                        </dd>
                    </div>
                    <div className="flex flex-wrap items-baseline justify-between gap-2 border-t border-border pt-3">
                        <dt className="text-faint">{t('Nhịp chạy')}</dt>
                        <dd>{frequency}</dd>
                    </div>
                </dl>
                <p className="mt-4 text-xs text-faint">{t('Tin tại nguồn vẫn được xét điều kiện trước khi gửi thông báo.')}</p>
            </PopoverContent>
        </Popover>
    );
}
