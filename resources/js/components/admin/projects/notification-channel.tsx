import { router } from '@inertiajs/react';
import { useState } from 'react';

import { update as updateTarget } from '@/actions/App/Http/Controllers/Admin/ProjectNotificationTargetController';
import { store as sendTestMessage } from '@/actions/App/Http/Controllers/Admin/ProjectTestMessageController';
import { update as updateWebhook } from '@/actions/App/Http/Controllers/Admin/ProjectWebhookController';
import { Field, FormSection } from '@/components/admin/form-section';
import { StatusDot } from '@/components/admin/status-dot';
import { WebhookDialog } from '@/components/admin/teams/webhook-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { useTranslation } from '@/lib/i18n';
import type { StatusDotData } from '@/types';

export type NotificationTarget = 'team' | 'project';

export interface ProjectGoogleChat {
    target: NotificationTarget;
    team: string;
    masked_webhook_url: string | null;
    status: StatusDotData;
    detail: string | null;
}

/**
 * Where the project's Google Chat messages go: its team's space, or a webhook of its own. On its own webhook the team no
 * longer hears about the project. A project without a webhook of its own gets one first: saving it moves the messages.
 */
export function NotificationChannel({ projectId, googleChat }: { projectId: number; googleChat: ProjectGoogleChat }) {
    const t = useTranslation();
    const [isSendingTest, setIsSendingTest] = useState(false);
    const [isSaving, setIsSaving] = useState(false);
    const [error, setError] = useState<string | undefined>();
    const [chosen, setChosen] = useState<NotificationTarget>(googleChat.target);
    const hasWebhook = googleChat.masked_webhook_url !== null;

    const choose = (target: NotificationTarget) => {
        setChosen(target);
        setError(undefined);

        if (target === googleChat.target || (target === 'project' && !hasWebhook)) {
            return;
        }

        router.put(updateTarget.url(projectId), { notification_target: target }, {
            preserveScroll: true,
            onStart: () => setIsSaving(true),
            onFinish: () => setIsSaving(false),
            onError: (errors) => {
                setChosen(googleChat.target);
                setError(errors.notification_target);
            },
        });
    };

    return (
        <FormSection title={t('Kênh thông báo')} description={t('Cảnh báo, bản tổng hợp và thread sự kiện của project gửi về đâu.')}>
            <div className="space-y-5">
                <Field label={t('Gửi về')} error={error}>
                    <RadioGroup value={chosen} onValueChange={(value) => choose(value as NotificationTarget)} disabled={isSaving}>
                        <label className="flex items-start gap-2.5 text-sm">
                            <RadioGroupItem value="team" />
                            <span>
                                <span className="font-medium">{t('Theo team')}</span>
                                <span className="block text-muted-foreground">{t('Gửi về Google Chat của team :team, chung với các project khác.', { team: googleChat.team })}</span>
                            </span>
                        </label>
                        <label className="flex items-start gap-2.5 text-sm">
                            <RadioGroupItem value="project" />
                            <span>
                                <span className="font-medium">{t('Webhook riêng của project')}</span>
                                <span className="block text-muted-foreground">{t('Chỉ gửi về webhook của project; team không nhận tin về project này nữa.')}</span>
                            </span>
                        </label>
                    </RadioGroup>
                </Field>
                {chosen === 'project' &&
                    (hasWebhook ? (
                        <Field
                            label={t('Webhook URL')}
                            htmlFor="project-webhook-url"
                            help={
                                <>
                                    <StatusDot status={googleChat.status} />
                                    {googleChat.detail && <span> · {googleChat.detail}</span>}
                                </>
                            }
                        >
                            <div className="flex gap-2 max-sm:flex-wrap">
                                <Input id="project-webhook-url" readOnly value={googleChat.masked_webhook_url ?? ''} className="flex-1 font-mono text-[calc(var(--fs-sm)-0.5px)] max-sm:basis-full" />
                                <Button
                                    type="button"
                                    variant="secondary"
                                    loading={isSendingTest}
                                    onClick={() =>
                                        router.post(sendTestMessage.url(projectId), {}, {
                                            preserveScroll: true,
                                            preserveState: true,
                                            onStart: () => setIsSendingTest(true),
                                            onFinish: () => setIsSendingTest(false),
                                        })
                                    }
                                >
                                    {t('Gửi thử')}
                                </Button>
                                <WebhookDialog action={updateWebhook(projectId)} hasWebhook title={t('Webhook riêng của project')} />
                            </div>
                        </Field>
                    ) : (
                        <div>
                            <p className="mb-3 text-muted-foreground">{t('Chưa có webhook riêng. Thông báo vẫn gửi về team cho tới khi lưu webhook.')}</p>
                            <WebhookDialog action={updateWebhook(projectId)} hasWebhook={false} title={t('Webhook riêng của project')} />
                        </div>
                    ))}
            </div>
        </FormSection>
    );
}
