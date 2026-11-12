import type { StatusDotData } from '@/types';

/** A repository connection as RepositoryConnectionController::summary() sends it; the token is only ever masked. */
export interface ConnectionSummary {
    id: number;
    name: string;
    account_login: string | null;
    /** E.g. "ghp_••••a1b2". */
    masked_token: string;
    /** The scopes of a classic token, e.g. "repo"; null for a fine-grained token. */
    scopes: string | null;
    expires_at: string | null;
    last_checked_at: string | null;
    last_error: string | null;
    status: StatusDotData;
}
