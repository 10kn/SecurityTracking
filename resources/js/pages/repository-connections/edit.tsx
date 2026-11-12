import { Link, router, useForm } from '@inertiajs/react';
import { RefreshCwIcon } from 'lucide-react';
import { useState } from 'react';

import { store as syncRepositories } from '@/actions/App/Http/Controllers/Admin/AccessibleRepositorySyncController';
import { destroy, index, update } from '@/actions/App/Http/Controllers/Admin/RepositoryConnectionController';
import { update as updateToken } from '@/actions/App/Http/Controllers/Admin/RepositoryConnectionTokenController';
import { edit as editService } from '@/actions/App/Http/Controllers/Admin/ServiceController';
import { Card, CardBody, CardHeader } from '@/components/admin/card';
import { ConfirmButton } from '@/components/admin/confirm-button';
import { CellTitle, EmptyState, Table, Td, Th, Tr } from '@/components/admin/data-table';
import { Field, FormActions, FormFields, FormSection } from '@/components/admin/form-section';
import { PageHeader } from '@/components/admin/page-header';
import type { ConnectionSummary } from '@/components/admin/repository-connections/connection-summary';
import { TokenDialog } from '@/components/admin/repository-connections/token-dialog';
import { StatusDot } from '@/components/admin/status-dot';
import { Tabs } from '@/components/admin/tabs';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';

interface Props {
    connection: ConnectionSummary;
    repositories: { full_name: string; default_branch: string | null; is_private: boolean }[];
    repositoriesSyncedAt: string | null;
    services: { id: number; name: string; project: string; repository: string; sync_error: string | null }[];
}

export default function RepositoryConnectionsEdit({ connection, repositories, repositoriesSyncedAt, services }: Props) {
    const t = useTranslation();
    const [tab, setTab] = useState<'settings' | 'repositories' | 'services'>('settings');
    const [isRefreshing, setIsRefreshing] = useState(false);
    const form = useForm({ name: connection.name });
    const serviceCount = services.length;

    const details = [
        connection.account_login && t('Account :account', { account: connection.account_login }),
        connection.scopes !== null && (connection.scopes === '' ? t('Không có scope') : t('Scope :scopes', { scopes: connection.scopes })),
        connection.expires_at ? t('Hết hạn :time', { time: connection.expires_at }) : t('Không hết hạn'),
        connection.last_checked_at && t('Kiểm tra lúc :time', { time: connection.last_checked_at }),
    ].filter(Boolean);

    return (
        <>
            <PageHeader
                title={connection.name}
                description={connection.account_login ?? connection.masked_token}
                breadcrumbs={[{ label: t('Kết nối repository'), href: index.url() }, { label: connection.name }]}
            />
            <Tabs
                label={t('Kết nối')}
                value={tab}
                onChange={setTab}
                items={[
                    { value: 'settings', label: t('Cài đặt') },
                    { value: 'repositories', label: t('Repository'), count: repositories.length },
                    { value: 'services', label: t('Services'), count: serviceCount },
                ]}
            />
            {tab === 'settings' && (
                <>
                    <form
                        className="rounded-lg border border-border bg-surface"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.submit(update(connection.id), { preserveScroll: true, onSuccess: () => form.setDefaults() });
                        }}
                        noValidate
                    >
                        <CardBody>
                            <FormSection title={t('Thông tin chung')} description={t('Tên hiện cạnh repository trong form service.')}>
                                <FormFields>
                                    <Field label={t('Tên kết nối')} htmlFor="name" required error={form.errors.name}>
                                        <Input id="name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} aria-invalid={form.errors.name !== undefined} />
                                    </Field>
                                </FormFields>
                            </FormSection>
                            <FormSection title={t('Token')} description={t('Hệ thống kiểm tra token mỗi ngày và báo admin khi token bị từ chối hoặc còn dưới 14 ngày là hết hạn.')}>
                                <Field
                                    label={t('Token')}
                                    htmlFor="masked-token"
                                    help={
                                        <>
                                            <StatusDot status={connection.status} />
                                            {details.length > 0 && <span> · {details.join(' · ')}</span>}
                                            {connection.last_error && <span className="mt-1 block text-danger-text">{connection.last_error}</span>}
                                        </>
                                    }
                                >
                                    <div className="flex gap-2 max-sm:flex-wrap">
                                        <Input id="masked-token" readOnly value={connection.masked_token} className="flex-1 font-mono text-[calc(var(--fs-sm)-0.5px)] max-sm:basis-full" />
                                        <TokenDialog action={updateToken(connection.id)} />
                                    </div>
                                </Field>
                            </FormSection>
                            <FormActions>
                                <Button type="submit" loading={form.processing}>
                                    {t('Lưu thay đổi')}
                                </Button>
                                <Button variant="secondary" asChild>
                                    <Link href={index.url()}>{t('Hủy')}</Link>
                                </Button>
                            </FormActions>
                        </CardBody>
                    </form>
                    <Card className="mt-6">
                        <CardBody>
                            <FormSection title={t('Xóa kết nối')} description={t('Chỉ xóa được khi không còn service nào đọc repository qua kết nối này.')}>
                                <ConfirmButton
                                    title={t('Xóa kết nối :connection?', { connection: connection.name })}
                                    description={t('Token bị xóa khỏi hệ thống. Không hoàn tác được.')}
                                    confirmLabel={t('Xóa kết nối')}
                                    disabled={serviceCount > 0}
                                    onConfirm={() => router.delete(destroy.url(connection.id))}
                                >
                                    {t('Xóa kết nối')}
                                </ConfirmButton>
                                {serviceCount > 0 && <p className="mt-1.5 text-sm text-muted-foreground">{t('Kết nối còn service đang dùng. Đổi repository của các service đó trước.')}</p>}
                            </FormSection>
                        </CardBody>
                    </Card>
                </>
            )}
            {tab === 'repositories' && (
                <Card>
                    <CardHeader>
                        <span className="text-sm text-muted-foreground">
                            {repositoriesSyncedAt === null ? t('Chưa có danh sách.') : t('Danh sách lấy lúc :time.', { time: repositoriesSyncedAt })}
                        </span>
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            disabled={isRefreshing}
                            onClick={() =>
                                router.post(
                                    syncRepositories.url(),
                                    { repository_connection_id: connection.id },
                                    {
                                        preserveState: true,
                                        preserveScroll: true,
                                        only: ['repositories', 'repositoriesSyncedAt', 'connection'],
                                        onStart: () => setIsRefreshing(true),
                                        onFinish: () => setIsRefreshing(false),
                                    },
                                )
                            }
                        >
                            <RefreshCwIcon className={cn(isRefreshing && 'animate-spin')} />
                            {t('Làm mới danh sách')}
                        </Button>
                    </CardHeader>
                    {repositories.length === 0 ? (
                        <EmptyState title={t('Account chưa đọc được repository nào')} description={t('Nhờ khách mời account vào repository, rồi bấm làm mới danh sách.')} />
                    ) : (
                        <Table>
                            <thead>
                                <tr>
                                    <Th>{t('Repository')}</Th>
                                    <Th>{t('Branch mặc định')}</Th>
                                    <Th>{t('Hiển thị')}</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {repositories.map((repository) => (
                                    <Tr key={repository.full_name}>
                                        <Td className="font-mono text-[calc(var(--fs-sm)-0.5px)]">{repository.full_name}</Td>
                                        <Td className="font-mono text-[calc(var(--fs-sm)-0.5px)] text-muted-foreground">{repository.default_branch ?? '—'}</Td>
                                        <Td className="text-muted-foreground">{repository.is_private ? t('private') : t('public')}</Td>
                                    </Tr>
                                ))}
                            </tbody>
                        </Table>
                    )}
                </Card>
            )}
            {tab === 'services' && (
                <Card>
                    {serviceCount === 0 ? (
                        <EmptyState title={t('Chưa có service nào dùng kết nối này')} description={t('Chọn repository của kết nối trong form service.')} />
                    ) : (
                        <Table>
                            <thead>
                                <tr>
                                    <Th>{t('Service')}</Th>
                                    <Th>{t('Repository')}</Th>
                                    <Th>{t('Đồng bộ')}</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {services.map((service) => (
                                    <Tr key={service.id} href={editService.url(service.id)}>
                                        <Td>
                                            <CellTitle title={service.name} sub={service.project} href={editService.url(service.id)} />
                                        </Td>
                                        <Td className="font-mono text-[calc(var(--fs-sm)-0.5px)]">{service.repository}</Td>
                                        <Td>
                                            <StatusDot status={service.sync_error === null ? { label: t('Bình thường'), tone: 'success' } : { label: t('Lần đồng bộ gần nhất lỗi'), tone: 'warning' }} />
                                        </Td>
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
