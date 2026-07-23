import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PasswordInput from '@/Components/PasswordInput';
import TextInput from '@/Components/TextInput';
import ContextHelp from './ContextHelp';

export default function FormField({ label, name, error, type, helpText, helpTooltip, helpTitle, ...props }) {
    const InputComponent = type === 'password' ? PasswordInput : TextInput;

    return (
        <div>
            {label ? (
                <div className="flex items-center gap-2">
                    <InputLabel htmlFor={name} value={label} />
                    <ContextHelp title={helpTitle}>{helpTooltip}</ContextHelp>
                </div>
            ) : null}
            <InputComponent id={name} name={name} type={type} className={`${label ? 'mt-1 ' : ''}block w-full`} {...props} />
            {helpText ? <p className="mt-1 text-xs leading-relaxed text-slate-500 dark:text-slate-400">{helpText}</p> : null}
            <InputError message={error} className="mt-2" />
        </div>
    );
}
