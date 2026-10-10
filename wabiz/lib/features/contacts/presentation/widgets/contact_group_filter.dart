import 'package:flutter/material.dart';

class ContactGroupFilter extends StatelessWidget {
  const ContactGroupFilter({
    required this.groups,
    required this.selectedGroup,
    required this.onSelected,
    super.key,
  });

  final List<String> groups;
  final String selectedGroup;
  final ValueChanged<String> onSelected;

  @override
  Widget build(BuildContext context) {
    final ColorScheme colors = Theme.of(context).colorScheme;
    return Wrap(
      spacing: 8,
      runSpacing: 4,
      children: [
        for (final String group in groups)
          ChoiceChip(
            label: Text(group),
            selected: selectedGroup == group,
            onSelected: (_) => onSelected(group),
            showCheckmark: false,
            selectedColor: colors.primaryContainer,
            labelStyle: TextStyle(
              color: selectedGroup == group
                  ? colors.onPrimaryContainer
                  : colors.onSurfaceVariant,
              fontWeight: selectedGroup == group
                  ? FontWeight.w600
                  : FontWeight.w500,
            ),
            side: BorderSide(
              color: selectedGroup == group
                  ? colors.primaryContainer
                  : colors.outlineVariant,
            ),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(30),
            ),
            padding: const EdgeInsets.symmetric(horizontal: 4),
          ),
      ],
    );
  }
}
