import { RouterProvider } from '@tanstack/react-router';
import { QueryClientProvider } from '@tanstack/react-query';
import { router } from './routes';
import { queryClient } from './lib/query-client';
import { ChatActivitySync } from './features/chat/ChatActivitySync';

export function App() {
    return (
        <QueryClientProvider client={queryClient}>
            <ChatActivitySync />
            <RouterProvider router={router} />
        </QueryClientProvider>
    );
}
