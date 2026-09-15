import type { ReactNode } from 'react';
import { Button } from '../../components/Button';
import { Icon } from '../../components/Icons';
import {
    unavailableMessage,
    type RealtimeAgentFeatureStatus,
    type RealtimeAgentStatus,
} from './use-realtime-agent';

interface RealtimeVoiceControlProps {
    availability?: RealtimeAgentFeatureStatus;
    status: RealtimeAgentStatus;
    error: Error | null;
    active: boolean;
    disabled?: boolean;
    onStart: () => Promise<void>;
    onStop: () => Promise<void>;
}

const STATUS_LABELS: Record<RealtimeAgentStatus, string> = {
    idle: 'Ready',
    connecting: 'Connecting',
    listening: 'Listening',
    processing: 'Thinking',
    speaking: 'Speaking',
    paused: 'Paused for chat',
    error: 'Session error',
};

/** Secondary live-session control; browser dictation remains a separate tool. */
export function RealtimeVoiceControl({
    availability,
    status,
    error,
    active,
    disabled = false,
    onStart,
    onStop,
}: RealtimeVoiceControlProps): ReactNode {
    const unavailable = availability?.available !== true;
    const title = unavailable
        ? unavailableMessage(availability?.reason)
        : error?.message ?? (active ? 'End Live assistant session' : 'Start Live assistant');

    return (
        <div className="chat-realtime-control" data-state={status}>
            <Button
                variant="secondary"
                size="sm"
                className="chat-realtime-button"
                data-testid="chat-realtime-toggle"
                aria-label={active ? 'End Live assistant session' : 'Start Live assistant'}
                aria-pressed={active}
                title={title}
                disabled={unavailable || (disabled && !active)}
                busy={status === 'connecting'}
                leadingIcon={active ? <Icon.StopCircle size={14} /> : <Icon.Waveform size={14} />}
                onClick={() => void (active ? onStop() : onStart()).catch(() => undefined)}
            >
                {active ? 'End live' : 'Live'}
            </Button>
            {active && status !== 'connecting' && (
                <span className="chat-realtime-status" role="status">
                    <span aria-hidden="true" />
                    {STATUS_LABELS[status]}
                </span>
            )}
        </div>
    );
}
