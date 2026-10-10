class Contact {
  const Contact({
    required this.id,
    required this.name,
    required this.phone,
    required this.group,
    required this.status,
    required this.lastActivity,
    required this.notes,
    this.recentlyAdded = false,
  });

  final int id;
  final String name;
  final String phone;
  final String group;
  final String status;
  final String lastActivity;
  final String notes;
  final bool recentlyAdded;

  Contact copyWith({
    String? name,
    String? phone,
    String? group,
    String? status,
    String? lastActivity,
    String? notes,
    bool? recentlyAdded,
  }) {
    return Contact(
      id: id,
      name: name ?? this.name,
      phone: phone ?? this.phone,
      group: group ?? this.group,
      status: status ?? this.status,
      lastActivity: lastActivity ?? this.lastActivity,
      notes: notes ?? this.notes,
      recentlyAdded: recentlyAdded ?? this.recentlyAdded,
    );
  }
}
