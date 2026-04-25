import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { QualityReportCard } from './QualityReportCard';

describe('QualityReportCard', () => {
    it('renders the empty-state card when report is null', () => {
        render(<QualityReportCard report={null} />);
        expect(screen.getByTestId('insight-card-quality')).toHaveAttribute(
            'data-state',
            'empty',
        );
        expect(screen.getByTestId('insight-card-quality-empty')).toBeInTheDocument();
    });

    it('renders summary KPIs + 5 histogram buckets when a report is present', () => {
        render(
            <QualityReportCard
                report={{
                    chunk_length_distribution: {
                        under_100: 2,
                        h100_500: 10,
                        h500_1000: 5,
                        h1000_2000: 1,
                        over_2000: 0,
                    },
                    outlier_short: 2,
                    outlier_long: 0,
                    missing_frontmatter: 3,
                    total_docs: 20,
                    total_chunks: 18,
                }}
            />,
        );

        expect(screen.getByTestId('insight-card-quality')).toHaveAttribute(
            'data-state',
            'ready',
        );
        expect(screen.getByTestId('insight-card-quality-summary')).toBeInTheDocument();
        // All 5 buckets present by testid.
        expect(screen.getByTestId('quality-bucket-under_100')).toBeInTheDocument();
        expect(screen.getByTestId('quality-bucket-h100_500')).toBeInTheDocument();
        expect(screen.getByTestId('quality-bucket-h500_1000')).toBeInTheDocument();
        expect(screen.getByTestId('quality-bucket-h1000_2000')).toBeInTheDocument();
        expect(screen.getByTestId('quality-bucket-over_2000')).toBeInTheDocument();
        // Missing-frontmatter footer renders with pluralisation.
        expect(screen.getByTestId('insight-card-quality-missing-fm')).toHaveTextContent(
            /3 canonical docs/,
        );
    });

    it('renders missing-frontmatter singular when count is 1', () => {
        render(
            <QualityReportCard
                report={{
                    chunk_length_distribution: {
                        under_100: 0,
                        h100_500: 0,
                        h500_1000: 0,
                        h1000_2000: 0,
                        over_2000: 0,
                    },
                    outlier_short: 0,
                    outlier_long: 0,
                    missing_frontmatter: 1,
                    total_docs: 1,
                    total_chunks: 0,
                }}
            />,
        );
        expect(screen.getByTestId('insight-card-quality-missing-fm')).toHaveTextContent(
            /1 canonical doc[^s]/,
        );
    });
});
