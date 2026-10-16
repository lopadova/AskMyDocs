import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App';
import './styles/globals.css';
import './styles/editorial.css';
import './styles/editorial-surfaces.css';
import './styles/design-themes.css';
import { initializeVisualTheme } from './lib/visual-theme';

initializeVisualTheme();

const rootEl = document.getElementById('root');
if (!rootEl) {
    throw new Error('Root container #root not found in document');
}

createRoot(rootEl).render(
    <StrictMode>
        <App />
    </StrictMode>,
);
