import 'faq_item.dart';

class InstitutionKnowledge {
  const InstitutionKnowledge({
    this.institutionName = '',
    this.description = '',
    this.studentCount,
    this.caregiverCount,
    this.teacherCount,
    this.services = '',
    this.address = '',
    this.googleMapsUrl = '',
    this.additionalInformation = '',
    this.serviceHours = '',
    this.phone = '',
    this.website = '',
    this.faqs = const [],
  });

  final String institutionName;
  final String description;
  final int? studentCount;
  final int? caregiverCount;
  final int? teacherCount;
  final String services;
  final String address;
  final String googleMapsUrl;
  final String additionalInformation;
  final String serviceHours;
  final String phone;
  final String website;
  final List<FaqItem> faqs;

  InstitutionKnowledge copyWith({
    String? institutionName,
    String? description,
    int? studentCount,
    bool clearStudentCount = false,
    int? caregiverCount,
    bool clearCaregiverCount = false,
    int? teacherCount,
    bool clearTeacherCount = false,
    String? services,
    String? address,
    String? googleMapsUrl,
    String? additionalInformation,
    String? serviceHours,
    String? phone,
    String? website,
    List<FaqItem>? faqs,
  }) => InstitutionKnowledge(
    institutionName: institutionName ?? this.institutionName,
    description: description ?? this.description,
    studentCount: clearStudentCount ? null : studentCount ?? this.studentCount,
    caregiverCount: clearCaregiverCount
        ? null
        : caregiverCount ?? this.caregiverCount,
    teacherCount: clearTeacherCount ? null : teacherCount ?? this.teacherCount,
    services: services ?? this.services,
    address: address ?? this.address,
    googleMapsUrl: googleMapsUrl ?? this.googleMapsUrl,
    additionalInformation: additionalInformation ?? this.additionalInformation,
    serviceHours: serviceHours ?? this.serviceHours,
    phone: phone ?? this.phone,
    website: website ?? this.website,
    faqs: faqs ?? this.faqs,
  );
}
