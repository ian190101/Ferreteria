const paths = {
    edit: 'M4 17.25V20h2.75L17.81 8.94l-2.75-2.75L4 17.25Zm15.71-10.04a1 1 0 0 0 0-1.42l-1.5-1.5a1 1 0 0 0-1.42 0l-1.06 1.06 2.75 2.75 1.23-1.23Z',
    trash: 'M6 7h12l-.8 13H6.8L6 7Zm3-3h6l1 1h4v2H4V5h4l1-1Zm1 5v9h2V9h-2Zm4 0v9h2V9h-2Z',
    power: 'M11 3h2v10h-2V3Zm5.66 2.34 1.41 1.41A8 8 0 1 1 5.93 6.75l1.41-1.41A6 6 0 1 0 16.66 5.34Z',
    check: 'M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2Z',
    close: 'm6.4 5 12.6 12.6-1.4 1.4L5 6.4 6.4 5Zm12.6 1.4L6.4 19 5 17.6 17.6 5 19 6.4Z',
    plus: 'M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5Z',
    eye: 'M12 5c5 0 8.5 4.2 9.5 7-1 2.8-4.5 7-9.5 7s-8.5-4.2-9.5-7C3.5 9.2 7 5 12 5Zm0 2c-3.7 0-6.4 2.8-7.3 5 .9 2.2 3.6 5 7.3 5s6.4-2.8 7.3-5C18.4 9.8 15.7 7 12 7Zm0 2.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5Z',
    receive: 'M4 4h16v5h-2V6H6v12h5v2H4V4Zm10 9h3V9h2v4h3l-4 5-4-5Zm-7-4h7v2H7V9Zm0 4h5v2H7v-2Z',
    convert: 'M7 7h8.6l-2.3-2.3L14.7 3 20 8.3l-5.3 5.3-1.4-1.7 2.3-2.3H7V7Zm10 10H8.4l2.3 2.3L9.3 21 4 15.7l5.3-5.3 1.4 1.7-2.3 2.3H17v2.6Z',
    bank: 'M12 3 3 8v2h18V8l-9-5ZM5 11h3v7H5v-7Zm5 0h4v7h-4v-7Zm6 0h3v7h-3v-7ZM4 19h16v2H4v-2Z',
    moon: 'M20 15.3A8.5 8.5 0 0 1 8.7 4a7 7 0 1 0 11.3 11.3Z',
    sun: 'M11 2h2v3h-2V2Zm0 17h2v3h-2v-3ZM4.2 3.8l2.1 2.1-1.4 1.4-2.1-2.1 1.4-1.4Zm15 15-1.4 1.4-2.1-2.1 1.4-1.4 2.1 2.1ZM2 11h3v2H2v-2Zm17 0h3v2h-3v-2ZM4.9 16.7l1.4 1.4-2.1 2.1-1.4-1.4 2.1-2.1ZM19.2 5.2l-2.1 2.1-1.4-1.4 2.1-2.1 1.4 1.4ZM12 7a5 5 0 1 1 0 10 5 5 0 0 1 0-10Z',
    menu: 'M4 6h16v2H4V6Zm0 5h16v2H4v-2Zm0 5h16v2H4v-2Z',
};

export default function Icon({ name, className = 'h-4 w-4' }) {
    return (
        <svg aria-hidden="true" className={className} viewBox="0 0 24 24" fill="currentColor">
            <path d={paths[name] ?? paths.eye} />
        </svg>
    );
}
