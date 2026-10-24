import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { show as showProject } from '@/actions/App/Http/Controllers/Admin/ProjectController';
import { destroy, index, update } from '@/actions/App/Http/Controllers/Admin/TeamController';
import { store as sendTestMessage } from '@/actions/App/Http/Controllers/Admin/TeamTestMessageController';
import { Card, CardBody } from '@/components/admin/card';
import { ConfirmButton } from '@/components/admin/confirm-button';
import { CellTitle, EmptyState, Table, Td, Th, Tr } from '@/components/admin/data-table';
import { Field, FormActions, FormSection } from '@/components/admin/form-section';
import { PageHeader } from '@/components/admin/page-header';
import { StatusDot } from '@/components/admin/status-dot';
import { Tabs } from '@/components/admin/tabs';
import { TeamGeneralFields, type TeamFormData } from '@/components/admin/teams/team-general-fields';
import { WebhookDialog } from '@/components/admin/teams/webhook-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/lib/i18n';
import type { StatusDotData } from '@/types';

interface Props {
    team: { id: number; name: string; slug: string };
    googleChat: { masked_webhook_url: string | null; status: StatusDotData; detail: string | null };
    projects: { data: { id: number; name: string; slug: string; repository_url: string | null; services_count: number }[] };
}

export default function TeamsEdit({ team, googleChat, projects }: Props) {
    const t = useTranslation();
    const [tab, setTab] = useState<'settings' | 'projects'>('settings');
    const [isSendingTest, setIsSendingTest] = useState(false);
    const form = useForm<TeamFormData>({ name: team.name, slug: team.slug });
    const projectCount = projects.data.length;

    return (
        <>
            <PageHeader title={team.name} description={t(':count project', { count: projectCount })} breadcrumbs={[{ label: t('Teams'), href: index.url() }, { label: team.name }]} />
            <Tabs
                label={t('Team')}
                value={tab}
                onChange={setTab}
                items={[
                    { value: 'settings', label: t('Cài đặt') },
                    { value: 'projects', label: t('Projects'), count: projectCount },
                ]}
            />
            {tab === 'settings' ? (
                <>
                    <form
                        className="rounded-lg border border-border bg-surface"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.submit(update(team.id), { preserveScroll: true, onSuccess: () => form.setDefaults() });
                        }}
                        noValidate
                    >
                        <CardBody>
                            <TeamGeneralFields form={form} isCreating={false} />
                            <FormSection title={t('Google Chat')} description={t('Kênh cảnh báo chính. Một lỗ hổng ảnh hưởng nhiều service chỉ gửi một tin.')}>
                                {googleChat.masked_webhook_url ? (
                                    <Field
                                        label={t('Webhook URL')}
                                        htmlFor="webhook-url"
                                        help={
                                            <>
                                                <StatusDot status={googleChat.status} />
                                                {googleChat.detail && <span> · {googleChat.detail}</span>}
                                            </>
                                        }
                                    >
                                        <div className="flex gap-2 max-sm:flex-wrap">
                                            <Input id="webhook-url" readOnly value={googleChat.masked_webhook_url} className="flex-1 font-mono text-[calc(var(--fs-sm)-0.5px)] max-sm:basis-full" />
                                            <Button
                                                type="button"
                                                variant="secondary"
                                                loading={isSendingTest}
                                                onClick={() =>
                                                    router.post(sendTestMessage.url(team.id), {}, {
                                                        preserveScroll: true,
                                                        preserveState: true,
                                                        onStart: () => setIsSendingTest(true),
                                                        onFinish: () => setIsSendingTest(false),
                                                    })
                                                }
                                            >
                                                {t('Gửi thử')}
                                            </Button>
                                            <WebhookDialog teamId={team.id} hasWebhook />
                                        </div>
                                    </Field>
                                ) : (
                                    <div>
                                        <p className="mb-3 text-muted-foreground">{t('Chưa có webhook. Team chưa nhận được cảnh báo.')}</p>
                                        <WebhookDialog teamId={team.id} hasWebhook={false} />
                                    </div>
                                )}
                            </FormSection>
                            <FormActions>
                                <Button type="submit" loading={form.processing}>
                                    {t('Lưu thay đổi')}
                                </Button>
                                <Button type="button" variant="secondary" disabled={!form.isDirty} onClick={() => form.resetAndClearErrors()}>
                                    {t('Hủy')}
                                </Button>
                            </FormActions>
                        </CardBody>
                    </form>
                    <Card className="mt-6">
                        <CardBody>
                            <FormSection title={t('Xóa team')} description={t('Chỉ xóa được khi team không còn project.')}>
                                <ConfirmButton
                                    title={t('Xóa team :team?', { team: team.name })}
                                    description={t('Không hoàn tác được.')}
                                    confirmLabel={t('Xóa team')}
                                    disabled={projectCount > 0}
                                    onConfirm={() => router.delete(destroy.url(team.id))}
                                >
                                    {t('Xóa team')}
                                </ConfirmButton>
                                {projectCount > 0 && <p className="mt-1.5 text-sm text-muted-foreground">{t('Team còn project. Chuyển project sang team khác trước.')}</p>}
                            </FormSection>
                        </CardBody>
                    </Card>
                </>
            ) : (
                <Card>
                    {projectCount === 0 ? (
                        <EmptyState title={t('Team chưa có project nào')} />
                    ) : (
                        <Table>
                            <thead>
                                <tr>
                                    <Th>{t('Project')}</Th>
                                    <Th>{t('Repository')}</Th>
                                    <Th align="right">{t('Services')}</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {projects.data.map((project) => (
                                    <Tr key={project.id} href={showProject.url(project.id)}>
                                        <Td>
                                            <CellTitle title={project.name} sub={project.slug} href={showProject.url(project.id)} />
                                        </Td>
                                        <Td className="font-mono text-sm text-muted-foreground">{project.repository_url ?? '—'}</Td>
                                        <Td align="right">{project.services_count}</Td>
                                    </Tr>
                                ))}
                            </tbody>
                        </Table>
                    )}
                </Card>
            )}
        </>
    );
}
