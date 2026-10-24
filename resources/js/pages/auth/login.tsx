import { Head, useForm } from '@inertiajs/react';
import { ShieldIcon } from 'lucide-react';

import { store } from '@/actions/App/Http/Controllers/Admin/SessionController';
import { Field } from '@/components/admin/form-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';

export default function Login() {
    const t = useTranslation();
    const form = useForm({ email: '', password: '', remember: false });

    return (
        <main className="grid min-h-screen place-items-center px-4 py-12">
            <Head title={t('Đăng nhập')} />
            <div className="w-full max-w-[400px]">
                <div className="mb-6 flex items-center gap-2.5 text-base font-semibold tracking-[-0.01em]">
                    <span className="grid size-6 place-items-center rounded-md bg-primary text-primary-foreground">
                        <ShieldIcon className="size-3.5" strokeWidth={1.8} />
                    </span>
                    Security Manager
                </div>
                <form
                    className="grid gap-[18px] rounded-lg border border-border bg-surface p-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.submit(store(), { onFinish: () => form.reset('password') });
                    }}
                    noValidate
                >
                    <div>
                        <h1 className="text-h1 font-semibold tracking-[-0.02em]">{t('Đăng nhập')}</h1>
                        <p className="mt-1 text-muted-foreground">{t('Chỉ admin đăng nhập được.')}</p>
                    </div>
                    <Field label={t('Email')} htmlFor="email" required error={form.errors.email}>
                        <Input
                            id="email"
                            type="email"
                            autoComplete="username"
                            autoFocus
                            value={form.data.email}
                            onChange={(event) => form.setData('email', event.target.value)}
                            aria-invalid={form.errors.email !== undefined}
                        />
                    </Field>
                    <Field label={t('Mật khẩu')} htmlFor="password" required error={form.errors.password}>
                        <Input
                            id="password"
                            type="password"
                            autoComplete="current-password"
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                            aria-invalid={form.errors.password !== undefined}
                        />
                    </Field>
                    <div className="flex items-center gap-2">
                        <Checkbox id="remember" checked={form.data.remember} onCheckedChange={(checked) => form.setData('remember', checked === true)} />
                        <Label htmlFor="remember" className="text-sm font-normal">
                            {t('Ghi nhớ đăng nhập')}
                        </Label>
                    </div>
                    <Button type="submit" loading={form.processing}>
                        {t('Đăng nhập')}
                    </Button>
                </form>
            </div>
        </main>
    );
}
