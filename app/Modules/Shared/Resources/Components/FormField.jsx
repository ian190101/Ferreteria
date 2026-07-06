import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';

export default function FormField({ label, name, error, ...props }) {
    return (
        <div>
            {label ? <InputLabel htmlFor={name} value={label} /> : null}
            <TextInput id={name} name={name} className={`${label ? 'mt-1 ' : ''}block w-full`} {...props} />
            <InputError message={error} className="mt-2" />
        </div>
    );
}
