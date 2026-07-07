import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PasswordInput from '@/Components/PasswordInput';
import TextInput from '@/Components/TextInput';

export default function FormField({ label, name, error, type, ...props }) {
    const InputComponent = type === 'password' ? PasswordInput : TextInput;

    return (
        <div>
            {label ? <InputLabel htmlFor={name} value={label} /> : null}
            <InputComponent id={name} name={name} type={type} className={`${label ? 'mt-1 ' : ''}block w-full`} {...props} />
            <InputError message={error} className="mt-2" />
        </div>
    );
}
