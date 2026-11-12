import { Field } from '@/components/admin/form-section';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/lib/i18n';

/**
 * The personal access token of a repository connection, typed once: the server asks GitHub about it before saving it,
 * and only shows it masked afterwards.
 */
export function TokenField({ value, error, onChange, autoFocus = false }: { value: string; error?: string; onChange: (token: string) => void; autoFocus?: boolean }) {
    const t = useTranslation();

    return (
        <Field
            label={t('Token')}
            htmlFor="token"
            required
            error={error}
            help={t('Outside collaborator chỉ dùng được token classic với scope repo; member của tổ chức dùng được token fine-grained (Contents: Read). Tổ chức bật SSO thì Authorize SSO cho token trước.')}
        >
            <Input
                id="token"
                type="password"
                autoComplete="off"
                spellCheck={false}
                className="font-mono text-[calc(var(--fs-sm)-0.5px)]"
                placeholder="ghp_… / github_pat_…"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                aria-invalid={error !== undefined}
                autoFocus={autoFocus}
            />
        </Field>
    );
}
