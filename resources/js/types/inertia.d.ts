import type { SharedProps, Toast } from '@/types';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: SharedProps;
        flashDataType: { toast?: Toast; incidentUpdate?: { request_id: string; status: 'queued' | 'duplicate' } };
    }
}
