import { useForm } from '@inertiajs/react';
import { useState } from 'react';

import { destroy as resumeMonitoring, store as pauseMonitoring } from '@/actions/App/Http/Controllers/Admin/ProjectMonitoringPauseController';
import { Field } from '@/components/admin/form-section';
import { Button } from '@/components/ui/button';
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { inputClassName } from '@/components/ui/input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** Pauses the monitoring of a project no longer maintained, with the reason. Its services are still scanned. */
export function PauseMonitoringDialog({ projectId }: { projectId: number }) {
    const t = useTranslation();
    const [isOpen, setIsOpen] = useState(false);
    const form = useForm({ reason: '' });

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(open) => {
                setIsOpen(open);

                if (!open) {
                    form.resetAndClearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button type="button" variant="secondary">
                    {t('Ngừng theo dõi')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.submit(pauseMonitoring(projectId), { preserveScroll: true, onSuccess: () => setIsOpen(false) });
                    }}
                    noValidate
                >
                    <DialogHeader>
                        <DialogTitle>{t('Ngừng theo dõi project')}</DialogTitle>
                        <DialogDescription>
                            {t('Team không nhận thêm thông báo nào về project này, kể cả lỗ hổng nghiêm trọng hay CISA KEV. Service vẫn được quét. Tin đã xếp hàng vẫn được gửi.')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogBody>
                        <Field label={t('Lý do')} htmlFor="pause-reason" required error={form.errors.reason} help={t('Ví dụ: project đã ngừng maintain từ tháng 10.')}>
                            <textarea
                                id="pause-reason"
                                rows={3}
                                maxLength={1000}
                                value={form.data.reason}
                                onChange={(event) => form.setData('reason', event.target.value)}
                                aria-invalid={form.errors.reason !== undefined}
                                className={cn(inputClassName, 'h-auto py-2')}
                                autoFocus
                            />
                        </Field>
                    </DialogBody>
                    <DialogFooter>
                        <Button type="button" variant="secondary" onClick={() => setIsOpen(false)}>
                            {t('Hủy')}
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            {t('Ngừng theo dõi')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Resumes the monitoring, choosing whether the team hears what it missed meanwhile. */
export function ResumeMonitoringDialog({ projectId }: { projectId: number }) {
    const t = useTranslation();
    const [isOpen, setIsOpen] = useState(false);
    const form = useForm<{ notify: boolean; note: string }>({ notify: true, note: '' });

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(open) => {
                setIsOpen(open);

                if (!open) {
                    form.resetAndClearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button type="button">{t('Mở lại')}</Button>
            </DialogTrigger>
            <DialogContent>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.submit(resumeMonitoring(projectId), { preserveScroll: true, onSuccess: () => setIsOpen(false) });
                    }}
                    noValidate
                >
                    <DialogHeader>
                        <DialogTitle>{t('Theo dõi lại project')}</DialogTitle>
                        <DialogDescription>
                            {t('Hạn xử lý của finding đang mở được tính lại từ hôm nay (không quá hạn CISA KEV). Đánh giá ảnh hưởng của các sự kiện liên quan phải làm lại.')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogBody>
                        <Field label={t('Những gì tồn đọng trong lúc ngừng theo dõi')} error={form.errors.notify}>
                            <RadioGroup value={form.data.notify ? 'send' : 'skip'} onValueChange={(value) => form.setData('notify', value === 'send')}>
                                <label className="flex items-start gap-2.5 text-sm">
                                    <RadioGroupItem value="send" />
                                    <span>
                                        <span className="font-medium">{t('Gửi')}</span>
                                        <span className="block text-muted-foreground">{t('Team nhận một tin tóm tắt tình trạng hiện tại; cảnh báo khẩn được gửi như bình thường.')}</span>
                                    </span>
                                </label>
                                <label className="flex items-start gap-2.5 text-sm">
                                    <RadioGroupItem value="skip" />
                                    <span>
                                        <span className="font-medium">{t('Không gửi')}</span>
                                        <span className="block text-muted-foreground">{t('Đánh dấu tồn đọng là đã đọc; team chỉ nhận thông báo về thay đổi mới.')}</span>
                                    </span>
                                </label>
                            </RadioGroup>
                        </Field>
                        <Field label={t('Ghi chú')} htmlFor="resume-note" error={form.errors.note}>
                            <textarea
                                id="resume-note"
                                rows={2}
                                maxLength={1000}
                                value={form.data.note}
                                onChange={(event) => form.setData('note', event.target.value)}
                                aria-invalid={form.errors.note !== undefined}
                                className={cn(inputClassName, 'h-auto py-2')}
                            />
                        </Field>
                    </DialogBody>
                    <DialogFooter>
                        <Button type="button" variant="secondary" onClick={() => setIsOpen(false)}>
                            {t('Hủy')}
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            {t('Mở lại')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
