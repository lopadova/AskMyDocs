import { createRoot } from 'react-dom/client';
import { LandingLanguageSelect } from './features/landing/LandingLanguageSelect';

const container = document.getElementById('language-select-root');
if (container) createRoot(container).render(<LandingLanguageSelect container={container} />);
