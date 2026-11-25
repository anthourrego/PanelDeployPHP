import { usePage } from '@inertiajs/react';

export function useAppUrl() {
    const { basePath } = usePage().props;

    return (path) => `${basePath}${path}`;
}
