/**
 * Delivery route helpers: great-circle distance, route cost and a simple
 * nearest-neighbour ordering refined with 2-opt passes.
 */

export interface Stop {
  id: string;
  lat: number;
  lon: number;
}

const EARTH_RADIUS_KM = 6371.0088;

function toRadians(degrees: number): number {
  return (degrees * Math.PI) / 180;
}

/** Haversine great-circle distance between two stops, in kilometres. */
export function haversineKm(a: Stop, b: Stop): number {
  const dLat = toRadians(b.lat - a.lat);
  const dLon = toRadians(b.lon - a.lon);
  const lat1 = toRadians(a.lat);
  const lat2 = toRadians(b.lat);

  const h =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLon / 2) ** 2;

  return 2 * EARTH_RADIUS_KM * Math.asin(Math.min(1, Math.sqrt(h)));
}

/** Total distance of depot -> stops in order -> depot. */
export function routeCostKm(depot: Stop, order: Stop[]): number {
  if (order.length === 0) {
    return 0;
  }
  let total = haversineKm(depot, order[0]);
  for (let i = 1; i < order.length; i++) {
    total += haversineKm(order[i - 1], order[i]);
  }
  return total + haversineKm(order[order.length - 1], depot);
}

/** Greedy ordering: always drive to the closest stop not yet visited. */
export function nearestNeighbourOrder(depot: Stop, stops: Stop[]): Stop[] {
  const remaining = [...stops];
  const ordered: Stop[] = [];
  let current = depot;

  while (remaining.length > 0) {
    let bestIndex = 0;
    let bestDistance = Number.POSITIVE_INFINITY;
    remaining.forEach((candidate, index) => {
      const distance = haversineKm(current, candidate);
      if (distance < bestDistance) {
        bestDistance = distance;
        bestIndex = index;
      }
    });
    current = remaining.splice(bestIndex, 1)[0];
    ordered.push(current);
  }
  return ordered;
}

/** Reverses segments while doing so shortens the route, up to maxPasses sweeps. */
export function twoOptImprove(depot: Stop, order: Stop[], maxPasses = 10): Stop[] {
  let best = [...order];
  let bestCost = routeCostKm(depot, best);

  for (let pass = 0; pass < maxPasses; pass++) {
    let improved = false;
    for (let i = 0; i < best.length - 1; i++) {
      for (let j = i + 1; j < best.length; j++) {
        const candidate = [
          ...best.slice(0, i),
          ...best.slice(i, j + 1).reverse(),
          ...best.slice(j + 1),
        ];
        const cost = routeCostKm(depot, candidate);
        if (cost + 1e-9 < bestCost) {
          best = candidate;
          bestCost = cost;
          improved = true;
        }
      }
    }
    if (!improved) {
      break;
    }
  }
  return best;
}

/** Entry point used by the dispatch planner. */
export function optimizeRoute(depot: Stop, stops: Stop[]): { order: Stop[]; costKm: number } {
  const order = twoOptImprove(depot, nearestNeighbourOrder(depot, stops));
  return { order, costKm: routeCostKm(depot, order) };
}
