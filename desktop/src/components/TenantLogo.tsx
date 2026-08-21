import { useEffect, useMemo, useState } from "react";
import { fetchTenantLogo } from "../lib/api";

interface Props {
  token: string;
  tenantName: string;
  logoUrl?: string | null;
  className?: string;
}

export function TenantLogo({ token, tenantName, logoUrl, className = "tenant-logo" }: Props) {
  const [src, setSrc] = useState<string | null>(null);
  const initials = useMemo(
    () => tenantName.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join("").toUpperCase() || "?",
    [tenantName],
  );

  useEffect(() => {
    if (!logoUrl) {
      setSrc(null);
      return;
    }
    let cancelled = false;
    let objectUrl: string | null = null;
    fetchTenantLogo(token, logoUrl)
      .then((url) => {
        objectUrl = url;
        if (cancelled) {
          URL.revokeObjectURL(url);
          return;
        }
        setSrc(url);
      })
      .catch(() => {
        if (!cancelled) setSrc(null);
      });
    return () => {
      cancelled = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [token, logoUrl]);

  return src ? (
    <img className={className} src={src} alt={`${tenantName} logo`} data-testid="tenant-logo-image" />
  ) : (
    <span className={`${className} tenant-logo-fallback`} aria-label={`${tenantName} logo`} data-testid="tenant-logo-fallback">
      {initials}
    </span>
  );
}
