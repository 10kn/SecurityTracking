/** A status shown as a small dot plus text (prototype v2). */
export interface StatusDotData {
    label: string;
    tone: 'danger' | 'warning' | 'success' | 'neutral' | 'unknown';
}

export interface Option<TValue = number | string> {
    value: TValue;
    label: string;
    description?: string;
}

export interface ThemeOption {
    value: string;
    label: string;
    description: string;
    swatch: string;
}

export interface Paginated<TItem> {
    data: TItem[];
    meta: {
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        per_page: number;
    };
}

/** Search, sort and page size of an admin table (App\Support\TableQuery). */
export interface TableState {
    search: string;
    sort: string;
    per_page: number;
    per_page_options: number[];
}

export interface Toast {
    type: 'success' | 'error';
    message: string;
    description?: string;
}

export interface SharedProps {
    auth: { user: { name: string; email: string; initials: string } | null };
    preferences: { theme: string; system_light_theme: string; locale: string };
    themes: ThemeOption[];
    locales: Option<string>[];
    translations: Record<string, string> | null;
}
