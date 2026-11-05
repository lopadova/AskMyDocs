import { useUiTranslation } from "../../lib/ui-locale";
import { Placeholder } from './Placeholder';

export function LogsPlaceholder() {
    const { t: translateInterface } = useUiTranslation();
    return (
        <Placeholder
            icon="Activity"
            title={translateInterface("Logs")}
            phase="Phase H"
            description="Five log tabs: chat logs, canonical audit, application log tail, activity log, and failed jobs. Stream-ready with cursor pagination."
        />
    );
}
