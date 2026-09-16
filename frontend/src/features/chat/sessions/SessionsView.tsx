import { type ReactNode } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { ConversationTitle } from '../ConversationTitle';
import { MessageThread } from '../MessageThread';
import { Composer } from '../Composer';
import { SuggestedFollowups } from '../SuggestedFollowups';
import { CitationDocumentModal } from '../CitationDocumentModal';
import { Icon } from '../../../components/Icons';
import { selectCurrentHash, useTeamStore } from '../../../lib/team-store';
import type { MessageCitation } from '../chat.api';
import { useChatSession } from '../use-chat-session';
import { SessionNavigation } from './SessionNavigation';

/**
 * The Sessions workspace: a ChatGPT/Claude-style shell over the SAME
 * chat engine as /chat.
 *
 * Everything below the layout comes from {@link useChatSession} — the
 * turn engine, the deferred-send queue, project scope, retrieval
 * filters, auto-titling, branch/edit/regenerate, the realtime session —
 * and every message, citation and composer control is the existing
 * component. What is new here is only the arrangement: the knowledge
 * base and the organised session list in the rail, the thread beside
 * them.
 *
 * Routes are FLAT SIBLINGS (`sessions`, `sessions/$conversationId`) for
 * the same reason the chat routes are: this component renders no
 * `<Outlet />`, so a nested child route would never mount.
 */
export function SessionsView(): ReactNode {
    const navigate = useNavigate();
    // Read the hash from the store rather than the hook's result: the nav
    // callbacks are built BEFORE useChatSession returns.
    const teamHash = useTeamStore(selectCurrentHash) ?? '';
    const session = useChatSession({
        nav: {
            toNewChat: () => navigate({ to: '/app/$teamHash/sessions', params: { teamHash } }),
            toConversation: (id) =>
                navigate({
                    to: '/app/$teamHash/sessions/$conversationId',
                    params: { teamHash, conversationId: String(id) },
                }),
        },
    });

    const {
        activeId,
        activeConversation,
        projectKey,
        projectLabel,
        projectScopeValue,
        teamProjectKeys,
        headerMeta,
        chat,
        realtime,
        realtimeAvailability,
        isStreaming,
        threadMessages,
        initialQuery,
        turnSettleId,
        filters,
        setFilters,
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

    const openKnowledgeBase = (): void => {
        navigate({ to: '/app/$teamHash/knowledge', params: { teamHash } });
    };

    /**
     * Per-surface KB deep-link: readers here go to the ungated Browse KB
     * page, unlike /chat which sends admins to the admin KB explorer.
     * That is the whole reason this stays out of the shared hook.
     */
    const openCitationInKb = (citation: MessageCitation): void => {
        if (citation.document_id == null) {
            return;
        }
        closeSource();
        navigate({
            to: '/app/$teamHash/knowledge',
            params: { teamHash },
            search: { doc: citation.document_id },
        });
    };

    return (
        <div
            data-testid="chat-sessions-view"
            style={{ display: 'flex', height: '100%', flex: 1, minWidth: 0 }}
        >
            <SessionNavigation
                projectKey={projectKey}
                projectScopeValue={projectScopeValue}
                teamProjectKeys={teamProjectKeys}
                onScopeChange={onScopeChange}
                projectSelectorDisabled={realtime.active}
                activeId={activeId}
                onSelect={onSelectConversation}
                onOpenKnowledgeBase={openKnowledgeBase}
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
                        <header data-testid="chat-sessions-header" className="chat-header-shell">
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
                                                    : `Session #${activeId}`
                                            }
                                        />
                                    ) : (
                                        <div className="chat-header-new-title">New session</div>
                                    )}
                                </div>
                                <div className="chat-header-meta">
                                    {/* Project lives in the sidebar on this
                                        surface, so the header shows the
                                        resolved scope as text — one
                                        chat-project-selector per page. */}
                                    <div className="chat-header-context-pill" data-kind="project">
                                        <span className="chat-header-context-icon" aria-hidden="true">
                                            <Icon.Folder size={11} />
                                        </span>
                                        <span className="chat-header-context-label">Project</span>
                                        <span
                                            className="chat-model-chip"
                                            data-testid="chat-sessions-project-label"
                                        >
                                            {projectLabel}
                                        </span>
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
            </SessionNavigation>

            {sourceCitation && (
                <CitationDocumentModal
                    citation={sourceCitation}
                    onClose={closeSource}
                    onOpenInKb={openCitationInKb}
                />
            )}
        </div>
    );
}
