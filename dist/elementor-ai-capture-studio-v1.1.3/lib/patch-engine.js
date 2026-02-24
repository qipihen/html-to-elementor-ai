export const PatchOperations = Object.freeze({
  SET: 'set',
  DELETE: 'delete',
  MOVE: 'move',
  COPY: 'copy',
  INSERT: 'insert',
  UPDATE: 'update'
});

export class PatchEngine {
  constructor(elementJson) {
    if (elementJson == null || typeof elementJson !== 'object') {
      throw new TypeError('PatchEngine requires an object as initial data');
    }

    this.original = structuredClone(elementJson);
    this.current = structuredClone(elementJson);
    this.changeLog = [];
  }

  setValue(path, value) {
    const oldValue = this.getValue(path);
    this.applyOperation({
      op: PatchOperations.SET,
      path,
      oldValue,
      newValue: value
    });
  }

  deleteNode(path) {
    const oldValue = this.getValue(path);
    this.applyOperation({
      op: PatchOperations.DELETE,
      path,
      oldValue
    });
  }

  move(fromPath, toPath) {
    const value = this.getValue(fromPath);
    this.applyOperation({
      op: PatchOperations.MOVE,
      fromPath,
      toPath,
      value
    });
  }

  applyOperation(operation) {
    switch (operation.op) {
      case PatchOperations.SET:
        this.setValueAt(operation.path, operation.newValue);
        break;
      case PatchOperations.DELETE:
        this.deleteAt(operation.path);
        break;
      case PatchOperations.MOVE:
        this.setValueAt(operation.toPath, operation.value);
        this.deleteAt(operation.fromPath);
        break;
      default:
        throw new Error(`Unsupported patch operation: ${operation.op}`);
    }

    this.changeLog.push(structuredClone(operation));
  }

  getValue(path) {
    if (!path) {
      return this.current;
    }

    const segments = parsePath(path);
    let node = this.current;

    for (const segment of segments) {
      if (node == null) {
        return undefined;
      }
      node = node[segment];
    }

    return node;
  }

  getChangeLog() {
    return structuredClone(this.changeLog);
  }

  export() {
    return {
      original: structuredClone(this.original),
      patched: structuredClone(this.current),
      changeLog: this.getChangeLog()
    };
  }

  setValueAt(path, value) {
    const segments = parsePath(path);
    if (segments.length === 0) {
      this.current = structuredClone(value);
      return;
    }

    const { parent, key } = this.resolveParent(segments, true);
    parent[key] = structuredClone(value);
  }

  deleteAt(path) {
    const segments = parsePath(path);
    if (segments.length === 0) {
      this.current = {};
      return;
    }

    const { parent, key } = this.resolveParent(segments, false);
    if (parent == null) {
      return;
    }

    if (Array.isArray(parent) && typeof key === 'number') {
      parent.splice(key, 1);
      return;
    }

    delete parent[key];
  }

  resolveParent(segments, createMissing) {
    const key = segments[segments.length - 1];
    let parent = this.current;

    for (let index = 0; index < segments.length - 1; index += 1) {
      const segment = segments[index];
      const nextSegment = segments[index + 1];

      if (parent[segment] == null) {
        if (!createMissing) {
          return { parent: undefined, key };
        }
        parent[segment] = typeof nextSegment === 'number' ? [] : {};
      }

      parent = parent[segment];
    }

    return { parent, key };
  }
}

function parsePath(path) {
  if (typeof path !== 'string') {
    throw new TypeError('Path must be a string');
  }

  return path
    .split('.')
    .filter((segment) => segment.length > 0)
    .map((segment) => (/^\d+$/.test(segment) ? Number(segment) : segment));
}

