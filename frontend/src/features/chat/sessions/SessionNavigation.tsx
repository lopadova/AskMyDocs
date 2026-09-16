import { useState, type ReactNode } from 'react';
import { Button } from '../../../components/Button';
import { Icon } from '../../../components/Icons';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogTitle,
    DialogTrigger,
} from '../../../components/ui/dialog';
import { SessionSidebar, type SessionSidebarProps } from './SessionSidebar';

/**
 * Keep the session list beside the thread on desktop and in a
 * keyboard-accessible drawer on small screens.
 *
 * Same shape as {@link ../ConversationNavigation}, which serves the
 * older /chat sidebar: a render-prop hands the drawer trigger to the
 * main column so the toggle can sit inside the thread header. The two
 * are separate because the panels they wrap differ — sharing would mean
 * a generic wrapper whose only job is to pick a child.
 */
export function SessionNavigation({
    children,
    ...props
}: SessionSidebarProps & {
    children: (historyToggle: ReactNode) => ReactNode;
}): ReactNode {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <div className="chat-history-desktop">
                <SessionSidebar {...props} />
            </div>
            {children(
                <DialogTrigger asChild>
                    <Button
                        variant="quiet"
                        size="sm"
                        iconOnly
                        className="chat-history-toggle"
                        data-testid="chat-sessions-drawer-toggle"
                        aria-label="Show sessions"
                        title="Show sessions"
                    >
                        <Icon.Menu size={16} />
                    </Button>
                </DialogTrigger>,
            )}
            <DialogContent className="chat-history-dialog" showCloseButton={false}>
                <DialogTitle className="sr-only">Sessions</DialogTitle>
                <DialogDescription className="sr-only">
                    Browse the knowledge base, search your sessions or start a new one.
                </DialogDescription>
                <DialogClose asChild>
                    <Button
                        variant="quiet"
                        size="sm"
                        iconOnly
                        className="chat-history-close"
                        aria-label="Close sessions"
                        title="Close sessions"
                    >
                        <Icon.Close size={15} />
                    </Button>
                </DialogClose>
                <SessionSidebar
                    {...props}
                    // Picking a session or opening the KB must dismiss the
                    // drawer, or the user lands on content hidden behind it.
                    onSelect={(id) => {
                        setOpen(false);
                        props.onSelect(id);
                    }}
                    onOpenKnowledgeBase={() => {
                        setOpen(false);
                        props.onOpenKnowledgeBase();
                    }}
                />
            </DialogContent>
        </Dialog>
    );
}
