import { showSystemError } from '@/Utils/systemErrors';
import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';

export default function SystemErrorFeedback() {
    const { errors, systemError } = usePage().props;

    useEffect(() => {
        if (systemError) {
            void showSystemError(systemError);
        } else if (errors && Object.keys(errors).length > 0) {
            void showSystemError({ errors, http_status: 422, code: 'VALIDATION_FAILED' });
        }
    }, [errors, systemError]);

    return null;
}
