type BrandWordmarkProps = { tone?: 'auto' | 'light' | 'dark' };

/** The original app mark with outlined lettering; no font download required. */
export function BrandWordmark({ tone = 'auto' }: BrandWordmarkProps) {
    return <span className="brand-wordmark" data-tone={tone} role="img" aria-label="AskMyDOcs">
        <img className="brand-wordmark-light" src="/brand/askmydocs-wordmark-light.svg" width={568} height={160} alt="" aria-hidden="true" />
        <img className="brand-wordmark-dark" src="/brand/askmydocs-wordmark-dark.svg" width={568} height={160} alt="" aria-hidden="true" />
    </span>;
}
