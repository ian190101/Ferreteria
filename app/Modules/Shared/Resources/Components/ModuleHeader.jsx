export default function ModuleHeader({ title, description }) {
    return (
        <header className="mb-6">
            <h1 className="text-[1.72rem] font-semibold leading-tight text-slate-950 dark:text-white">
                {title}
            </h1>
            {description && (
                <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-500 dark:text-slate-400">
                    {description}
                </p>
            )}
        </header>
    );
}
