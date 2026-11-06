import type { SVGAttributes } from 'react';

/** Neutral placeholder mark: a rounded square with a cross cut out. Swap it for the real logo. */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 32 32"
            fill="currentColor"
            xmlns="http://www.w3.org/2000/svg"
        >
            <path
                fillRule="evenodd"
                clipRule="evenodd"
                d="M8 0h16a8 8 0 0 1 8 8v16a8 8 0 0 1-8 8H8a8 8 0 0 1-8-8V8a8 8 0 0 1 8-8Zm5.5 6v5.5H8v5h5.5V26h5v-9.5H24v-5h-5.5V6h-5Z"
            />
        </svg>
    );
}
