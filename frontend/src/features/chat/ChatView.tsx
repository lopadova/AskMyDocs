import { type ReactNode } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { ConversationNavigation } from './ConversationNavigation';
import { ConversationTitle } from './ConversationTitle';
import { MessageThread } from './MessageThread';
import { Composer } from './Composer';
import { ProjectSelector } from './ProjectSelector';
import type { MessageCitation } from './chat.api';
import { selectCurrentHash, useTeamStore } from '../../lib/team-store';
import { Icon } from '../../components/Icons';
import { Button } from '../../components/Button';
import { SuggestedFollowups } from './SuggestedFollowups';
import { CitationDocumentModal } from './CitationDocumentModal';
import { ConversationDebugDownloadButton } from './ConversationDebugDownloadButton';
import { useChatSession } from './use-chat-session';

/**
 * Chat feature root. Two columns:
 *   - ConversationList (sidebar)
 *   - Thread + Composer (centre)
 *
 * The route `/app/$teamHash/chat/$conversationId?` drives the active
 * conversation; navigating programmatically updates the URL via TanStack
 * Router.
 *
 * This component is PRESENTATION ONLY. Every piece of orchestration —
 * URL↔store sync, project scope, retrieval filters, the deferred-send
 * queue, auto-titling, branch/edit/regenerate, the realtime session —
 * lives in the shared {@link useChatSession} hook, which the
 * ChatGPT-style Sessions workspace consumes too. A behaviour change
 * belongs in the hook so both surfaces get it; never fork it here.
 *
 * The one thing that CANNOT be shared is navigation: TanStack's `to` is
 * a typed literal, so each surface injects its own targets (this one
 * points at `/chat`, the Sessions workspace at `/sessions`).
 */
export function ChatView(): ReactNode {
    const navigate = useNavigate();
    // Read the hash straight from the store rather than from the hook's
    // result: the nav callbacks are built BEFORE `useChatSession` returns,
    // so closing over its result would be a temporal-dead-zone trap.
    const teamHash = useTeamStore(selectCurrentHash) ?? '';
    const session = useChatSession({
        nav: {
            toNewChat: () => navigate({ to: '/app/$teamHash/chat', params: { teamHash } }),
            toConversation: (id) =>
                navigate({
                    to: '/app/$teamHash/chat/$conversationId',
                    params: { teamHash, conversationId: String(id) },
                }),
        },
    });

    const {
        activeId,
        activeConversation,
        activeConversationKnown,
        activeConversationResolved,
        projectKey,
        projectLabel,
        projectScopeValue,
        teamProjectKeys,
        headerMeta,
        canViewKb,
        chat,
        realtime,
        realtimeAvailability,
        isStreaming,
        threadMessages,
        initialQuery,
        turnSettleId,
        filters,
        setFilters,
        depth,
        setDepth,
        collections,
        liveSources,
        liveSourceSelection,
        onLiveSourcesChange,
        showCounterfactual,
        onSelectConversation,
        onScopeChange,
        requireConversation,
        handleSend,
        handleMcpAppMessage,
        handleAgentArtifactSelection,
        handleRegenerate,
        handleBranchAt,
        handleEditUserMessage,
        sourceCitation,
        openSource,
        closeSource,
        threadError,
        composerError,
    } = session;

    // Admin-only secondary action inside the citation modal: jump to the
    // full KB document page (deep-link behind the admin/super-admin RBAC
    // gate). Per-surface on purpose — the Sessions workspace sends readers
    // to the ungated Browse KB page instead.
    const handleOpenInKb = (citation: MessageCitation) => {
        if (citation.document_id == null) {
            return;
        }
        closeSource();
        navigate({
            to: '/app/$teamHash/admin/kb',
            params: { teamHash },
            search: { doc: citation.document_id, tab: 'preview' },
        });
    };

    return (
        <div data-testid="chat-view" style={{ display: 'flex', height: '100%', flex: 1, minWidth: 0 }}>
            <ConversationNavigation
                projectKey={projectKey}
                onSelect={onSelectConversation}
                onNewAnonymous={() =>
                    navigate({ to: '/app/$teamHash/chat/anonymous', params: { teamHash } })
                }
            >
                {(historyToggle) => (
                    <div
                        className="chat-main-column grid-bg"
                        style={{
                            flex: 1,
                            display: 'flex',
                            flexDirection: 'column',
                            minWidth: 0,
                            minHeight: 0,
                            overflow: 'hidden',
                            position: 'relative',
                        }}
                    >
                        <header
                            data-testid="chat-header"
                            className="chat-header-shell"
                        >
                            {historyToggle}
                            <span className="chat-header-icon" aria-hidden="true">
                                <Icon.Chat size={17} />
                            </span>
                            <div className="chat-header-content">
                                <div className="chat-header-title-row">
                                    {activeId !== null ? (
                                        <ConversationTitle
                                            conversationId={activeId}
                                            title={
                                                activeConversation?.title?.trim()
                                                    ? activeConversation.title
                                                    : activeConversationKnown
                                                      ? `Conversation #${activeId}`
                                                      : activeConversationResolved
                                                        ? 'Not found'
                                                        : 'Loading…'
                                            }
                                        />
                                    ) : (
                                        <div className="chat-header-new-title">New chat</div>
                                    )}
                                </div>
                                <div className="chat-header-meta">
                                    <div className="chat-header-context-pill" data-kind="project">
                                        <span className="chat-header-context-icon" aria-hidden="true">
                                            <Icon.Folder size={11} />
                                        </span>
                                        <span className="chat-header-context-label">Project</span>
                                        <ProjectSelector
                                            value={projectScopeValue}
                                            projects={teamProjectKeys}
                                            allowAll
                                            disabled={realtime.active}
                                            onChange={onScopeChange}
                                        />
                                    </div>
                                    <div className="chat-header-context-pill" data-kind="model">
                                        <span className="chat-header-context-icon" aria-hidden="true">
                                            <Icon.Brain size={11} />
                                        </span>
                                        <span className="chat-header-context-label">Model</span>
                                        <span className="chat-model-chip">{headerMeta}</span>
                                    </div>
                                </div>
                            </div>
                            {activeId !== null && (
                                <ConversationDebugDownloadButton conversationId={activeId} />
                            )}
                            <Button
                                variant="secondary"
                                size="sm"
                                iconOnly
                                className="chat-header-more"
                                data-testid="chat-header-more"
                                aria-label="Conversation actions"
                                title="Conversation actions"
                            >
                                <Icon.MoreH size={14} />
                            </Button>
                        </header>

                        <MessageThread
                            conversationId={activeId}
                            projectKey={projectKey}
                            messages={threadMessages}
                            sdkStatus={chat.status}
                            isLoadingHistory={initialQuery.isLoading}
                            error={threadError}
                            onRegenerate={handleRegenerate}
                            onBranchAt={handleBranchAt}
                            onEditUserMessage={handleEditUserMessage}
                            showCounterfactual={showCounterfactual}
                            onOpenSource={openSource}
                            onMcpAppMessage={handleMcpAppMessage}
                            onAgentArtifactSelection={handleAgentArtifactSelection}
                            agentEvents={chat.events}
                            activeAgentRunId={chat.activeRun?.run_id ?? null}
                            awaitingAgentConfirmation={chat.confirmation !== null}
                            onCancelAgent={chat.stop}
                            onContinueAgent={() => void chat.continueRun()}
                        />

                        <SuggestedFollowups
                            conversationId={activeId}
                            turnId={turnSettleId}
                            isStreaming={isStreaming}
                            onPick={(prompt) => void handleSend(prompt)}
                        />

                        <Composer
                            conversationId={activeId}
                            projectLabel={projectLabel}
                            projectKey={projectKey}
                            modelLabel={headerMeta}
                            onRequireConversation={requireConversation}
                            availableProjects={teamProjectKeys}
                            availableCollections={collections}
                            filters={filters}
                            onFiltersChange={setFilters}
                            depth={depth}
                            onDepthChange={setDepth}
                            liveSources={liveSources}
                            liveSourceSelection={liveSourceSelection}
                            onLiveSourcesChange={onLiveSourcesChange}
                            onSend={handleSend}
                            onStop={chat.stop}
                            isStreaming={isStreaming}
                            realtime={{ ...realtime, availability: realtimeAvailability }}
                            error={composerError}
                        />
                    </div>
                )}
            </ConversationNavigation>

            {sourceCitation && (
                <CitationDocumentModal
                    citation={sourceCitation}
                    onClose={closeSource}
                    onOpenInKb={canViewKb ? handleOpenInKb : undefined}
                />
            )}
        </div>
    );
}
