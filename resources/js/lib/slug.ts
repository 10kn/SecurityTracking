/**
 * Like Laravel's Str::slug for the names admins type, e.g. "Thanh toán Đà Nẵng" → "thanh-toan-da-nang".
 */
export function slugify(text: string): string {
    return text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[đĐ]/g, 'd')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}
