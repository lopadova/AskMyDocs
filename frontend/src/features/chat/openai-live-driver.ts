import { OpenAILiveDriver } from '@agents-full-duplex/realtime-agent-client';

const ICE_GATHERING_TIMEOUT_MS = 5_000;

type OpenAILiveDriverOptions = {
    mediaDevices?: MediaDevices;
    peerFactory?: () => RTCPeerConnection;
};

/**
 * Prepare browser audio before the package starts its data-channel timeout.
 * This keeps a slow browser permission prompt from consuming the WebRTC
 * negotiation window.
 */
export async function createAskMyDocsOpenAILiveDriver(
    request: typeof fetch,
    options: OpenAILiveDriverOptions = {},
): Promise<OpenAILiveDriver> {
    const mediaDevices = options.mediaDevices ?? globalThis.navigator?.mediaDevices;
    if (!mediaDevices?.getUserMedia) {
        throw new DOMException('Microphone capture is not supported by this browser.', 'NotFoundError');
    }

    const stream = await mediaDevices.getUserMedia({ audio: true });
    const streamBackedMediaDevices = {
        getUserMedia: async () => stream,
    } as MediaDevices;

    return new OpenAILiveDriver(
        request,
        options.peerFactory ?? createCandidateCompletePeerConnection,
        streamBackedMediaDevices,
    );
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
