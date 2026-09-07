import { useState, type ReactNode } from 'react';
import { Button } from '../../components/Button';
import { Icon } from '../../components/Icons';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogTitle, DialogTrigger } from '../../components/ui/dialog';
import { ConversationList, type ConversationListProps } from './ConversationList';

/** Keep history beside the thread on desktop and in a keyboard-accessible drawer on small screens. */
export function ConversationNavigation({ children, ...props }: ConversationListProps & {
    children: (historyToggle: ReactNode) => ReactNode;
}): ReactNode {
    const [open, setOpen] = useState(false);
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <div className="chat-history-desktop"><ConversationList {...props} /></div>
            {children(
                <DialogTrigger asChild>
                    <Button variant="quiet" size="sm" iconOnly className="chat-history-toggle" aria-label="Show conversations" title="Show conversations">
                        <Icon.Menu size={16} />
                    </Button>
                </DialogTrigger>,
            )}
            <DialogContent className="chat-history-dialog" showCloseButton={false}>
                <DialogTitle className="sr-only">Conversations</DialogTitle>
                <DialogDescription className="sr-only">Search your history or start a new conversation.</DialogDescription>
                <DialogClose asChild>
                    <Button variant="quiet" size="sm" iconOnly className="chat-history-close" aria-label="Close conversations" title="Close conversations">
                        <Icon.Close size={15} />
                    </Button>
                </DialogClose>
                <ConversationList
                    {...props}
                    onSelect={(id) => { setOpen(false); props.onSelect(id); }}
                    onNewAnonymous={() => { setOpen(false); props.onNewAnonymous(); }}
                />
            </DialogContent>
        </Dialog>
    );
}
