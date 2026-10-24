import { useState } from 'react';

import { slugify } from '@/lib/slug';

/**
 * While creating a record the slug follows the name, until the admin edits the slug. Editing never changes it, because
 * a new slug breaks links in earlier Chat messages.
 */
export function useSlugFromName(isCreating: boolean, setSlug: (slug: string) => void) {
    const [isSlugEdited, setIsSlugEdited] = useState(!isCreating);

    return {
        onNameChange: (name: string) => {
            if (!isSlugEdited) {
                setSlug(slugify(name));
            }
        },
        onSlugChange: (slug: string) => {
            setIsSlugEdited(slug !== '');
            setSlug(slug);
        },
    };
}
