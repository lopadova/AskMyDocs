// Minimal icon set — stroke 1.5, 16×16 default via currentColor
const I = ({ d, size = 16, fill = 'none', sw = 1.6, style }) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill={fill} stroke="currentColor"
       strokeWidth={sw} strokeLinecap="round" strokeLinejoin="round" style={style}>
    {d}
  </svg>
);

const Icon = {
  Logo: (p) => (
    <svg width={p.size||20} height={p.size||20} viewBox="0 0 24 24" fill="none">
      <defs>
        <linearGradient id="lg" x1="0" x2="1" y1="0" y2="1">
          <stop offset="0" stopColor="#8b5cf6"/>
          <stop offset="1" stopColor="#22d3ee"/>
        </linearGradient>
      </defs>
      <path d="M4 5.5 A1.5 1.5 0 0 1 5.5 4 H16 L20 8 V18.5 A1.5 1.5 0 0 1 18.5 20 H5.5 A1.5 1.5 0 0 1 4 18.5 Z"
            fill="url(#lg)" opacity=".18" stroke="url(#lg)" strokeWidth="1.4"/>
      <path d="M8 11 H16 M8 14.5 H13" stroke="url(#lg)" strokeWidth="1.6" strokeLinecap="round"/>
      <circle cx="18" cy="16" r="2.3" fill="url(#lg)"/>
    </svg>
  ),
  Search:  (p) => <I {...p} d={<><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></>} />,
  Chat:    (p) => <I {...p} d={<path d="M4 5.5A1.5 1.5 0 0 1 5.5 4h13A1.5 1.5 0 0 1 20 5.5v9A1.5 1.5 0 0 1 18.5 16H10l-4 4v-4H5.5A1.5 1.5 0 0 1 4 14.5z"/>} />,
  Bolt:    (p) => <I {...p} d={<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>} />,
  Grid:    (p) => <I {...p} d={<><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></>} />,
  Users:   (p) => <I {...p} d={<><circle cx="9" cy="8" r="3.5"/><path d="M2 20c.5-3.4 3.2-5.5 7-5.5s6.5 2.1 7 5.5"/><circle cx="17" cy="6" r="2.5"/><path d="M15 13c3.5 0 6 1.7 6.5 5"/></>} />,
  Shield:  (p) => <I {...p} d={<path d="M12 3 4 6v6c0 5 3.5 8.2 8 9 4.5-.8 8-4 8-9V6z"/>} />,
  Folder:  (p) => <I {...p} d={<path d="M3 6.5A1.5 1.5 0 0 1 4.5 5H9l2 2h8.5A1.5 1.5 0 0 1 21 8.5v9a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 17.5z"/>} />,
  File:    (p) => <I {...p} d={<><path d="M6 3h8l4 4v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M14 3v5h5"/></>} />,
  Book:    (p) => <I {...p} d={<><path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M6 17h13"/></>} />,
  Terminal:(p) => <I {...p} d={<><rect x="3" y="4" width="18" height="16" rx="2"/><path d="m7 9 3 3-3 3M13 15h5"/></>} />,
  Wrench:  (p) => <I {...p} d={<path d="M20 7a4 4 0 0 1-5.3 3.8l-7 7a2 2 0 1 1-2.8-2.8l7-7A4 4 0 0 1 15.7 3 L13 5.7l1.6 1.7 1.7 1.6z"/>} />,
  Sparkles:(p) => <I {...p} d={<><path d="m12 3 1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9l4.4-1.6z"/><path d="M19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z"/></>} />,
  Activity:(p) => <I {...p} d={<path d="M3 12h4l2-7 4 14 2-7h6"/>} />,
  Bell:    (p) => <I {...p} d={<><path d="M6 16v-5a6 6 0 1 1 12 0v5l1.5 2h-15z"/><path d="M10 20a2 2 0 0 0 4 0"/></>} />,
  Settings:(p) => <I {...p} d={<><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3h.1a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8v.1a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></>} />,
  Sun:     (p) => <I {...p} d={<><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M2 12h2M20 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"/></>} />,
  Moon:    (p) => <I {...p} d={<path d="M20 14.5A8 8 0 1 1 9.5 4a7 7 0 0 0 10.5 10.5z"/>} />,
  Mic:     (p) => <I {...p} d={<><rect x="9" y="3" width="6" height="12" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/></>} />,
  Send:    (p) => <I {...p} d={<path d="M4 12 21 4l-8 17-2-7z"/>} />,
  Chevron: (p) => <I {...p} d={<path d="m9 6 6 6-6 6"/>} />,
  ChevronDown: (p) => <I {...p} d={<path d="m6 9 6 6 6-6"/>} />,
  Copy:    (p) => <I {...p} d={<><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/></>} />,
  Check:   (p) => <I {...p} d={<path d="m5 12 4 4 10-10"/>} />,
  Close:   (p) => <I {...p} d={<path d="M6 6l12 12M18 6 6 18"/>} />,
  Plus:    (p) => <I {...p} d={<path d="M12 5v14M5 12h14"/>} />,
  Play:    (p) => <I {...p} d={<path d="M7 4v16l14-8z" />} fill="currentColor" />,
  Quote:   (p) => <I {...p} d={<path d="M7 7H4v6h3l-2 4M17 7h-3v6h3l-2 4"/>} />,
  Link:    (p) => <I {...p} d={<><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/></>} />,
  Brain:   (p) => <I {...p} d={<path d="M8 4.5a3 3 0 0 1 4 0 3 3 0 0 1 4 0c2 0 3.5 1.5 3.5 3.5 0 1-.5 2-1 2.5.5.5 1 1.5 1 2.5 0 2-1.5 3.5-3.5 3.5a3 3 0 0 1-4 0 3 3 0 0 1-4 0C6 16.5 4.5 15 4.5 13c0-1 .5-2 1-2.5-.5-.5-1-1.5-1-2.5C4.5 6 6 4.5 8 4.5z"/>} />,
  Globe:   (p) => <I {...p} d={<><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"/></>} />,
  Filter:  (p) => <I {...p} d={<path d="M3 5h18l-7 9v5l-4 2v-7z"/>} />,
  Download:(p) => <I {...p} d={<><path d="M12 4v12m-5-5 5 5 5-5"/><path d="M4 20h16"/></>} />,
  Upload:  (p) => <I {...p} d={<><path d="M12 20V8m-5 5 5-5 5 5"/><path d="M4 4h16"/></>} />,
  Clock:   (p) => <I {...p} d={<><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></>} />,
  Alert:   (p) => <I {...p} d={<><path d="M12 3 2 21h20z"/><path d="M12 9v5M12 17.5v.5"/></>} />,
  Eye:     (p) => <I {...p} d={<><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></>} />,
  Edit:    (p) => <I {...p} d={<><path d="M4 20h4l11-11a2.8 2.8 0 0 0-4-4L4 16z"/><path d="M13 6l4 4"/></>} />,
  Trash:   (p) => <I {...p} d={<><path d="M4 7h16M9 7V4h6v3M6 7l1 13a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-13"/></>} />,
  Menu:    (p) => <I {...p} d={<path d="M4 6h16M4 12h16M4 18h16"/>} />,
  MoreH:   (p) => <I {...p} d={<><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></>} fill="currentColor" sw={0}/>,
  Tag:     (p) => <I {...p} d={<><path d="M3 11V5a2 2 0 0 1 2-2h6l10 10-8 8z"/><circle cx="8" cy="8" r="1.5"/></>} />,
  Git:     (p) => <I {...p} d={<><circle cx="6" cy="6" r="2.5"/><circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="12" r="2.5"/><path d="M6 8.5v7M8.5 18a6 6 0 0 0 6-6"/></>} />,
  Branch:  (p) => <I {...p} d={<><circle cx="6" cy="5" r="2"/><circle cx="6" cy="19" r="2"/><circle cx="18" cy="12" r="2"/><path d="M6 7v10M8 12h8"/></>} />,
  Zap:     (p) => <I {...p} d={<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>} />,
  Cube:    (p) => <I {...p} d={<><path d="m12 3 9 5-9 5-9-5z"/><path d="M3 8v8l9 5 9-5V8M12 13v8"/></>} />,
  Database:(p) => <I {...p} d={<><ellipse cx="12" cy="5" rx="8" ry="2.5"/><path d="M4 5v6c0 1.4 3.6 2.5 8 2.5s8-1.1 8-2.5V5M4 11v6c0 1.4 3.6 2.5 8 2.5s8-1.1 8-2.5v-6"/></>} />,
  Calendar:(p) => <I {...p} d={<><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 9h18M8 3v4M16 3v4"/></>} />,
  Sliders: (p) => <I {...p} d={<><path d="M4 6h16M4 12h16M4 18h16"/><circle cx="8" cy="6" r="1.8" fill="currentColor"/><circle cx="15" cy="12" r="1.8" fill="currentColor"/><circle cx="11" cy="18" r="1.8" fill="currentColor"/></>} />,
  Share:   (p) => <I {...p} d={<><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="m8 11 8-4M8 13l8 4"/></>} />,
  Command: (p) => <I {...p} d={<path d="M6 4a3 3 0 1 1 0 6h12a3 3 0 1 1 0-6v12a3 3 0 1 1 0 6H6a3 3 0 1 1 0-6z"/>} />,
};

window.Icon = Icon;
