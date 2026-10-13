import { OpenAILiveDriver } from '@agents-full-duplex/realtime-agent-client';

const ICE_GATHERING_TIMEOUT_MS = 5_000;

type OpenAILiveDriverOptions = {
    mediaDevices?: MediaDevices;
    peerFactory?: () => RTCPeerConnection;
};

export type AskMyDocsOpenAILiveDriver = OpenAILiveDriver & {
    sendProgress: (content: string) => Promise<void>;
};

/**
 * Prepare browser audio before the package starts its data-channel timeout.
 * This keeps a slow browser permission prompt from consuming the WebRTC
 * negotiation window.
 */
export async function createAskMyDocsOpenAILiveDriver(
    request: typeof fetch,
    options: OpenAILiveDriverOptions = {},
): Promise<AskMyDocsOpenAILiveDriver> {
    const mediaDevices = options.mediaDevices ?? globalThis.navigator?.mediaDevices;
    if (!mediaDevices?.getUserMedia) {
        throw new DOMException('Microphone capture is not supported by this browser.', 'NotFoundError');
    }

    const stream = await mediaDevices.getUserMedia({ audio: true });
    const streamBackedMediaDevices = {
        getUserMedia: async () => stream,
    } as MediaDevices;

    const progress = createLiveProgressChannel(options.peerFactory ?? createCandidateCompletePeerConnection);
    const driver = new OpenAILiveDriver(
        request,
        progress.peerFactory,
        streamBackedMediaDevices,
    );

    return Object.assign(driver, { sendProgress: progress.sendProgress });
}

/** Spoken context goes straight to Live; it must not become a user message or another tool request. */
export function createLiveProgressChannel(createPeer: () => RTCPeerConnection) {
    let channel: RTCDataChannel | null = null;
    return {
        peerFactory: (): RTCPeerConnection => {
            const peer = createPeer();
            const createChannel = peer.createDataChannel.bind(peer);
            peer.createDataChannel = (label, options) => {
                const created = createChannel(label, options);
                if (label === 'oai-events') channel = created;
                return created;
            };
            return peer;
        },
        sendProgress: async (content: string): Promise<void> => {
            if (channel?.readyState !== 'open') throw new Error('The Live progress channel is closed.');
            // GPT-Live accepts up to 500 tokens per append. These short notices
            // are capped well below that and carry no source content or reasoning.
            channel.send(JSON.stringify({
                type: 'session.commentary.append',
                event_id: `progress_${globalThis.crypto.randomUUID()}`,
                delegation_id: null,
                content: content.slice(0, 600),
            }));
        },
    };
}

/**
 * The package posts the object returned by createOffer(). Ensure that object
 * contains the candidates added asynchronously to localDescription; there is
 * no later signalling request through which trickled candidates can be sent.
 */
export function includeGatheredIceCandidates(
    peer: RTCPeerConnection,
    timeoutMs = ICE_GATHERING_TIMEOUT_MS,
): RTCPeerConnection {
    const createOffer = peer.createOffer.bind(peer);
    const setLocalDescription = peer.setLocalDescription.bind(peer);
    let pendingOffer: RTCSessionDescriptionInit | null = null;

    peer.createOffer = (async (options?: RTCOfferOptions): Promise<RTCSessionDescriptionInit> => {
        const offer = await createOffer(options);
        pendingOffer = { type: offer.type, sdp: offer.sdp };

        return pendingOffer;
    }) as RTCPeerConnection['createOffer'];

    peer.setLocalDescription = async (description?: RTCLocalSessionDescriptionInit): Promise<void> => {
        await setLocalDescription(description);
        if (description?.type !== 'offer') return;

        await waitForIceGathering(peer, timeoutMs);
        const gatheredSdp = peer.localDescription?.sdp;
        if (pendingOffer && gatheredSdp) pendingOffer.sdp = gatheredSdp;
    };

    return peer;
}

function createCandidateCompletePeerConnection(): RTCPeerConnection {
    return includeGatheredIceCandidates(new RTCPeerConnection());
}

async function waitForIceGathering(peer: RTCPeerConnection, timeoutMs: number): Promise<void> {
    if (peer.iceGatheringState === 'complete') return;

    await new Promise<void>((resolve) => {
        const finish = (): void => {
            globalThis.clearTimeout(timeout);
            peer.removeEventListener('icegatheringstatechange', handleStateChange);
            resolve();
        };
        const handleStateChange = (): void => {
            if (peer.iceGatheringState === 'complete') finish();
        };
        const timeout = globalThis.setTimeout(finish, Math.max(0, timeoutMs));

        peer.addEventListener('icegatheringstatechange', handleStateChange);
        handleStateChange();
    });
}
