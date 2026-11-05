import { Icon } from '../../components/Icons';
import { researchTaskCopy, type ResearchTask } from '../agent/research-tasks';

export function AgentResearchTasks({ tasks, locale }: { tasks: ResearchTask[]; locale: string }) {
    if (tasks.length < 2) return null;
    const copy = researchTaskCopy(locale);
    return (
        <section className="agent-research-tasks" aria-label={copy.title} data-testid="agent-research-tasks">
            <div className="agent-research-header">
                <div><strong>{copy.title}</strong><p>{copy.subtitle}</p></div>
                <span className="agent-research-count">{copy.progress(tasks.filter((task) => task.settled).length, tasks.length)}</span>
            </div>
            <ol>
                {tasks.map((task) => {
                    const working = ['documents', 'researching', 'verifying'].includes(task.status);
                    const warning = ['partial', 'unverified', 'conflicting', 'failed'].includes(task.status);
                    return (
                        <li key={task.id} data-task-id={task.id} data-status={task.status}>
                            <span className="agent-research-number" aria-hidden="true">{task.id + 1}</span>
                            <span className="agent-research-question">{task.question}</span>
                            <span className="agent-research-status">
                                <span className="agent-research-icon" data-working={working || undefined} aria-hidden="true">
                                    {task.status === 'answered' ? <Icon.Check size={14} />
                                        : warning ? <Icon.Alert size={14} />
                                            : task.status === 'cancelled' ? <Icon.Close size={14} />
                                                : working ? <Icon.Activity size={14} /> : <Icon.Clock size={14} />}
                                </span>
                                {copy.labels[task.status]}
                            </span>
                        </li>
                    );
                })}
            </ol>
        </section>
    );
}
