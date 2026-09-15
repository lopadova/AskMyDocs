import { describe, expect, it, vi } from 'vitest';
import {
    createAskMyDocsOpenAILiveDriver,
    includeGatheredIceCandidates,
} from './openai-live-driver';

class FakeIcePeer extends EventTarget {
    iceGatheringState: RTCIceGatheringState = 'new';
    localDescription: RTCSessionDescription | null = null;

    async createOffer(): Promise<RTCSessionDescriptionInit> {
        return { type: 'offer', sdp: 'v=0\r\n' };
    }

    async setLocalDescription(description?: RTCLocalSessionDescriptionInit): Promise<void> {
        this.localDescription = {
            type: description?.type ?? 'offer',
            sdp: 'v=0\r\na=candidate:1 1 UDP 1 127.0.0.1 5000 typ host\r\n',
            toJSON: () => ({
                type: description?.type ?? 'offer',
                sdp: 'v=0\r\na=candidate:1 1 UDP 1 127.0.0.1 5000 typ host\r\n',
            }),
        };
        this.iceGatheringState = 'complete';
        this.dispatchEvent(new Event('icegatheringstatechange'));
    }
}

describe('AskMyDocs OpenAI Live browser adapter', () => {
    it('copies gathered ICE candidates into the offer posted by the package', async () => {
        const peer = includeGatheredIceCandidates(
            new FakeIcePeer() as unknown as RTCPeerConnection,
        );

        const offer = await peer.createOffer();
        await peer.setLocalDescription(offer);

        expect(offer.sdp).toContain('a=candidate:');
    });

    it('acquires the microphone before constructing the package driver', async () => {
        const stream = { getTracks: vi.fn(() => []) } as unknown as MediaStream;
        const getUserMedia = vi.fn().mockResolvedValue(stream);
        const peerFactory = vi.fn(() => new FakeIcePeer() as unknown as RTCPeerConnection);

        const driver = await createAskMyDocsOpenAILiveDriver(
            vi.fn() as unknown as typeof fetch,
            {
                mediaDevices: { getUserMedia } as unknown as MediaDevices,
                peerFactory,
            },
        );

        expect(getUserMedia).toHaveBeenCalledWith({ audio: true });
        expect(peerFactory).not.toHaveBeenCalled();
        expect(driver).toBeDefined();
    });
});
