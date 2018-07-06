#!/usr/bin/env python3
# -*- coding: utf-8 -*-
#    Copyright (C) 2017-2018 CIRCL Computer Incident Response Center Luxembourg (smile gie)
#    Copyright (C) 2017-2018 Christian Studer
#
#    This program is free software: you can redistribute it and/or modify
#    it under the terms of the GNU Affero General Public License as
#    published by the Free Software Foundation, either version 3 of the
#    License, or (at your option) any later version.
#
#    This program is distributed in the hope that it will be useful,
#    but WITHOUT ANY WARRANTY; without even the implied warranty of
#    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
#    GNU Affero General Public License for more details.
#
#    You should have received a copy of the GNU Affero General Public License
#    along with this program.  If not, see <http://www.gnu.org/licenses/>.

import sys, json, os, time, uuid, io
import stix2
from pymisp import MISPEvent, MISPObject, __path__
from stix2misp_mapping import *
from collections import defaultdict

galaxy_types = {'attack-pattern': 'Attack Pattern', 'intrusion-set': 'Intrusion Set',
                'malware': 'Malware', 'threat-actor': 'Threat Actor', 'tool': 'Tool'}
with open(os.path.join(__path__[0], 'data/describeTypes.json'), 'r') as f:
    misp_types = json.loads(f.read())['result'].get('types')

class StixParser():
    def __init__(self):
        self.misp_event = MISPEvent()
        self.event = []
        self.misp_event['Galaxy'] = []

    def loadEvent(self, args, pathname):
        try:
            filename = os.path.join(pathname, args[1])
            tempFile = open(filename, 'r', encoding='utf-8')
            self.filename = filename
            event = json.loads(tempFile.read())
            self.stix_version = 'stix {}'.format(event.get('spec_version'))
            for o in event.get('objects'):
                try:
                    try:
                        self.event.append(stix2.parse(o, allow_custom=True))
                    except:
                        self.parse_custom(o)
                except:
                    pass
            if not self.event:
                print(json.dumps({'success': 0, 'message': 'There is no valid STIX object to import'}))
                sys.exit(1)
            self.load_mapping()
        except:
            print(json.dumps({'success': 0, 'message': 'The STIX file could not be read'}))
            sys.exit(1)

    def parse_custom(self, obj):
        custom_object_type = obj.pop('type')
        labels = obj['labels']
        try:
            @stix2.CustomObject(custom_object_type,[('id', stix2.properties.StringProperty(required=True)),
            ('x_misp_timestamp', stix2.properties.StringProperty(required=True)),
            ('labels', stix2.properties.ListProperty(labels, required=True)),
            ('x_misp_value', stix2.properties.StringProperty(required=True)),
            ('created_by_ref', stix2.properties.StringProperty(required=True)),
            ('x_misp_comment', stix2.properties.StringProperty()),
            ('x_misp_category', stix2.properties.StringProperty())
            ])
            class Custom(object):
                def __init__(self, **kwargs):
                    return
            custom = Custom(**obj)
        except:
            @stix2.CustomObject(custom_object_type,[('id', stix2.properties.StringProperty(required=True)),
            ('x_misp_timestamp', stix2.properties.StringProperty(required=True)),
            ('labels', stix2.properties.ListProperty(labels, required=True)),
            ('x_misp_values', stix2.properties.DictionaryProperty(required=True)),
            ('created_by_ref', stix2.properties.StringProperty(required=True)),
            ('x_misp_comment', stix2.properties.StringProperty()),
            ('x_misp_category', stix2.properties.StringProperty())
            ])
            class Custom(object):
                def __init__(self, **kwargs):
                    return
            custom = Custom(**obj)
        self.event.append(stix2.parse(custom))

    def load_mapping(self):
        self.objects_mapping = {'asn': {'observable': observable_asn, 'pattern': pattern_asn},
                                'domain-ip': {'observable': observable_domain_ip, 'pattern': pattern_domain_ip},
                                'email': {'observable': self.observable_email, 'pattern': self.pattern_email},
                                'file': {'observable': observable_file, 'pattern': pattern_file},
                                'ip-port': {'observable': observable_ip_port, 'pattern': pattern_ip_port},
                                'network-socket': {'observable': observable_socket, 'pattern': pattern_socket},
                                'process': {'observable': observable_process, 'pattern': pattern_process},
                                'registry-key': {'observable': observable_regkey, 'pattern': pattern_regkey},
                                'url': {'observable': observable_url, 'pattern': pattern_url},
                                'WindowsPEBinaryFile': {'observable': self.observable_pe, 'pattern': self.pattern_pe},
                                'x509': {'observable': observable_x509, 'pattern': pattern_x509}}

    def handler(self):
        self.outputname = '{}.stix2'.format(self.filename)
        if self.from_misp():
            self.buildMispDict()
        else:
            self.version_attribute = {'type': 'text', 'object_relation': 'version', 'value': self.stix_version}
            self.buildExternalDict()

    def from_misp(self):
        for o in self.event:
            if o._type == 'report' and 'misp:tool="misp2stix2"' in o.get('labels'):
                index = self.event.index(o)
                self.report = self.event.pop(index)
                return True
        return False

    def buildMispDict(self):
        self.parse_identity()
        self.parse_report()
        for o in self.event:
            try:
                object_type = o._type
            except:
                object_type = o['type']
            labels = o.get('labels')
            if object_type in galaxy_types:
                self.parse_galaxy(o, labels)
            elif object_type == 'course-of-action':
                self.parse_course_of_action(o)
            elif 'x-misp-object' in object_type:
                if 'from_object' in labels:
                    self.parse_custom_object(o)
                else:
                    self.parse_custom_attribute(o, labels)
            else:
                if 'from_object' in labels:
                    self.parse_object(o, labels)
                else:
                    self.parse_attribute(o, labels)

    def parse_identity(self):
        identity = self.event.pop(0)
        org = {'name': identity.get('name')}
        self.misp_event['Org'] = org

    def parse_report(self):
        report = self.report
        self.misp_event.info = report.get('name')
        if report.get('published'):
            self.misp_event.publish_timestamp = self.getTimestampfromDate(report.get('published'))
        if hasattr(report, 'labels'):
            labels = report['labels']
            for l in labels:
                self.misp_event.add_tag(l)
        if hasattr(report, 'external_references'):
            ext_refs = report['external_references']
            for e in ext_refs:
                link = {"type": "link"}
                comment = e.get('source_name')
                try:
                    comment = comment.split('url - ')[1]
                except:
                    pass
                if comment:
                    link['comment'] = comment
                link['value'] = e.get('url')
                self.misp_event.add_attribute(**link)

    def parse_galaxy(self, o, labels):
        galaxy_type = self.get_misp_type(labels)
        tag = labels[1]
        value = tag.split(':')[1].split('=')[1]
        galaxy_description, cluster_description = o.get('description').split('|')
        galaxy = {'type': galaxy_type, 'name': o.get('name'), 'description': galaxy_description,
                  'GalaxyCluster': [{'type': galaxy_type, 'value':value, 'tag_name': tag,
                                     'description': cluster_description}]}
        self.misp_event['Galaxy'].append(galaxy)

    def parse_course_of_action(self, o):
        misp_object = MISPObject('course-of-action')
        if 'name' in o:
            attribute = {'type': 'text', 'object_relation': 'name', 'value': o.get('name')}
            misp_object.add_attribute(**attribute)
        else:
            return
        if 'description' in o:
            attribute = {'type': 'text', 'object_relation': 'description', 'value': o.get('description')}
            misp_object.add_attribute(**attribute)
        self.misp_event.add_object(**misp_object)

    def parse_custom_object(self, o):
        name = o.get('type').split('x-misp-object-')[1]
        timestamp = self.getTimestampfromDate(o.get('x_misp_timestamp'))
        category = o.get('category')
        attributes = []
        values = o.get('x_misp_values')
        for v in values:
            attribute_type, object_relation = v.split('_')
            attribute = {'type': attribute_type, 'value': values.get(v),
                         'object_relation': object_relation}
            attributes.append(attribute)
        misp_object = {'name': name, 'timestamp': timestamp, 'meta-category': category,
                       'Attribute': attributes}
        self.misp_event.add_object(**misp_object)

    def parse_custom_attribute(self, o, labels):
        attribute_type = o.get('type').split('x-misp-object-')[1]
        if attribute_type not in misp_types:
            attribute_type = attribute_type.replace('-', '|')
        timestamp = self.getTimestampfromDate(o.get('x_misp_timestamp'))
        to_ids = bool(labels[1].split('=')[1])
        value = o.get('x_misp_value')
        category = self.get_misp_category(labels)
        attribute = {'type': attribute_type, 'timestamp': timestamp, 'to_ids': to_ids,
                     'value': value, 'category': category}
        self.misp_event.add_attribute(**attribute)

    def parse_object(self, o, labels):
        object_type = self.get_misp_type(labels)
        name = 'file' if object_type == 'WindowsPEBinaryFile' else object_type
        object_category = self.get_misp_category(labels)
        stix_type = o._type
        misp_object = MISPObject(name)
        misp_object['meta-category'] = object_category
        if stix_type == 'indicator':
            pattern = o.get('pattern').replace('\\\\', '\\').split(' AND ')
            pattern[0] = pattern[0][1:]
            pattern[-1] = pattern[-1][:-1]
            attributes = self.objects_mapping[object_type]['pattern'](pattern)
        if stix_type == 'observed-data':
            observable = o.get('objects')
            attributes = self.objects_mapping[object_type]['observable'](observable)
        if isinstance(attributes, tuple):
            attributes, pe_uuid = attributes
            misp_object.add_reference(pe_uuid, 'included-in')
        for attribute in attributes:
            misp_object.add_attribute(**attribute)
        misp_object.to_ids = bool(labels[1].split('=')[1])
        self.misp_event.add_object(**misp_object)

    def parse_attribute(self, o, labels):
        attribute_type = self.get_misp_type(labels)
        attribute_category = self.get_misp_category(labels)
        attribute = {'type': attribute_type, 'category': attribute_category}
        stix_type = o._type
        if stix_type == 'vulnerability':
            value = o.get('name')
        else:
            if stix_type == 'indicator':
                o_date = o.get('valid_from')
                pattern = o.get('pattern').replace('\\\\', '\\')
                value = self.parse_pattern(pattern)
                attribute['to_ids'] = True
            else:
                o_date = o.get('first_observed')
                observable = o.get('objects')
                try:
                    value = self.parse_observable(observable, attribute_type)
                except:
                    print('{}: {}'.format(attribute_type, observable))
                attribute['to_ids'] = False
            attribute['timestamp'] = self.getTimestampfromDate(o_date)
        if 'description' in o:
            attribute['comment'] = o.get('description')
        try:
            attribute['value'] = value
            self.misp_event.add_attribute(**attribute)
        except:
            pass

    @staticmethod
    def observable_email(observable):
        attributes = []
        addresses = {}
        files = {}
        for o_key, o_dict in observable.items():
            part_type = o_dict._type
            if part_type == 'email-addr':
                addresses[o_key] = o_dict.get('value')
            elif part_type == 'file':
                files[o_key] = o_dict.get('name')
            else:
                message = dict(o_dict)
        attributes.append({'type': 'email-src', 'object_relation': 'from',
                           'value': addresses[message.pop('from_ref')], 'to_ids': False})
        for ref in ('to_refs', 'cc_refs'):
            if ref in message:
                for item in message.pop(ref):
                    mapping = email_mapping[ref]
                    attributes.append({'type': mapping['type'], 'object_relation': mapping['relation'],
                                       'value': addresses[item], 'to_ids': False})
        if 'body_multipart' in message:
            for f in message.pop('body_multipart'):
                attributes.append({'type': 'email-attachment', 'object_relation': 'attachment',
                                   'value': files[f.get('body_raw_ref')], 'to_ids': False})
        for m_key, m_value in message.items():
            if m_key == 'additional_header_fields':
                for field_key, field_value in m_value.items():
                    mapping = email_mapping[field_key]
                    if field_key == 'Reply-To':
                        for rt in field_value:
                            attributes.append({'type': mapping['type'],
                                               'object_relation': mapping['relation'],
                                               'value': rt, 'to_ids': False})
                    else:
                        attributes.append({'type': mapping['type'],
                                           'object_relation': mapping['relation'],
                                           'value': field_value, 'to_ids': False})
            else:
                try:
                    mapping = email_mapping[m_key]
                    attributes.append({'type': mapping['type'], 'object_relation': mapping['relation'],
                                       'value': m_value, 'to_ids': False})
                except:
                    if m_key.startswith("x_misp_attachment_"):
                        attribute_type, relation = m_key.split("x_misp_")[1].split("_")
                        attributes.append({'type': attribute_type, 'object_relation': relation, 'to_ids': False,
                                           'value': m_value['value'], 'data': io.BytesIO(m_value['data'].encode())})
                    elif "x_misp_" in m_key:
                        attribute_type, relation = m_key.split("x_misp_")[1].split("_")
                        attributes.append({'type': attribute_type, 'object_relation': relation,
                                           'value': m_value, 'to_ids': False})
        return attributes

    @staticmethod
    def pattern_email(pattern):
        attributes = []
        attachments = defaultdict(dict)
        for p in pattern:
            p_type, p_value = p.split(' = ')
            try:
                mapping = email_mapping[p_type]
                attributes.append({'type': mapping['type'], 'object_relation': mapping['relation'],
                                   'value': p_value[1:-1], 'to_ids': True})
            except KeyError:
                if p_type.startswith("email-message:'x_misp_attachment_"):
                    relation, field = p_type.split('.')
                    relation = relation.split(':')[1][1:-1]
                    attachments[relation][field] = p_value[1:-1]
                elif "x_misp_" in p_type:
                    attribute_type, relation = p_type.split("x_misp_")[1][:-1].split("_")
                    attributes.append({'type': attribute_type, 'object_relation': relation,
                                       'value': p_value[1:-1], 'to_ids': True})
        for a_key, a_dict in attachments.items():
            _, _, attribute_type, relation = a_key.split('_')
            attributes.append({'type': attribute_type, 'object_relation': relation, 'to_ids': True,
                               'value': a_dict['value'], 'data': io.BytesIO(a_dict['data'].encode())})
        return attributes

    def observable_pe(self, observable):
        extension = observable['0']['extensions']['windows-pebinary-ext']
        sections = extension['sections']
        pe = MISPObject('pe')
        pe_uuid = str(uuid.uuid4())
        pe.uuid = pe_uuid
        self.fill_object_attributes_observable(pe, pe_mapping, extension)
        for section in sections:
            pe_section = MISPObject('pe-section')
            if 'hashes' in section:
                for h_type, h_value in section['hashes'].items():
                    h_type = h_type.lower().replace('-', '')
                    pe_section.add_attribute(**{'type': h_type, 'object_relation': h_type,
                                                'value': h_value, 'to_ids': False})
            self.fill_object_attributes_observable(pe_section, pe_section_mapping, section)
            section_uuid = str(uuid.uuid4())
            pe_section.uuid = section_uuid
            pe.add_reference(section_uuid, 'included-in')
            self.misp_event.add_object(**pe_section)
        self.misp_event.add_object(**pe)
        return observable_file(observable), pe_uuid

    @staticmethod
    def fill_object_attributes_observable(misp_object, mapping_dict, stix_object):
        for stix_type, value in stix_object.items():
            try:
                mapping = mapping_dict[stix_type]
            except KeyError:
                continue
            misp_object.add_attribute(**{'type': mapping['type'], 'object_relation': mapping['relation'],
                                         'value': value, 'to_ids': False})

    def pattern_pe(self, pattern):
        attributes = []
        sections = defaultdict(dict)
        pe = MISPObject('pe')
        pe_uuid = str(uuid.uuid4())
        pe.uuid = pe_uuid
        for p in pattern:
            p_type, p_value = p.split(' = ')
            p_value = p_value[1:-1]
            if ':extensions.' in p_type:
                if '.sections[' in p_type:
                    p_type_list = p_type.split('.')
                    stix_type = "hashes.{}".format(p_type_list[4][1:-1]) if '.hashes.' in p_type else p_type_list[3]
                    sections[p_type_list[2]][stix_type] = p_value
                else:
                    stix_type = p_type.split('.')[-1]
                    mapping = pe_mapping[stix_type]
                    pe.add_attribute(**{'type': mapping['type'], 'object_relation': mapping['relation'],
                                        'value': p_value, 'to_ids': True})
            else:
                if 'file:hashes.' in p_type :
                    _, h = p_type.split('.')
                    h = h[1:-1]
                    attributes.append({'type': h, 'object_relation': h, 'value': p_value, 'to_ids': True})
                else:
                    mapping = file_mapping[p_type]
                    attributes.append({'type': mapping['type'], 'object_relation': mapping['relation'],
                                       'value': p_value, 'to_ids': True})
        for _, section in sections.items():
            pe_section = MISPObject('pe-section')
            for stix_type, value in section.items():
                if 'hashes.' in stix_type:
                    h_type = stix_type.split('.')[1]
                    pe_section.add_attribute(**{'type': h_type, 'object_relation': h_type,
                                                'value': value, 'to_ids': True})
                else:
                    mapping = pe_section_mapping[stix_type]
                    pe_section.add_attribute(**{'type': mapping['type'], 'object_relation': mapping['relation'],
                                                'value': value, 'to_ids': True})
            section_uuid = str(uuid.uuid4())
            pe_section.uuid = pe_uuid
            pe.add_reference(section_uuid, 'included-in')
            self.misp_event.add_object(**pe_section)
        self.misp_event.add_object(**pe)
        return attributes, pe_uuid

    def buildExternalDict(self):
        self.fetch_report()
        for o in self.event:
            object_type = o._type
            if object_type in ('relationship', 'report'):
                continue
            if object_type in galaxy_types:
                self.parse_external_galaxy(o)
            elif object_type == 'vulnerability':
                attribute = {'type': 'vulnerability', 'value': o.get('name')}
                if 'description' in o:
                    attribute['comment'] = o.get('description')
                self.misp_event.add_attribute(**attribute)
            elif object_type == 'course-of-action':
                self.parse_course_of_action(o)
            elif object_type == 'indicator':
                pattern = o.get('pattern')
                self.parse_external_pattern(pattern)
                attribute = {'type': 'stix2-pattern', 'object_relation': 'stix2-pattern', 'value': pattern}
                misp_object = {'name': 'stix2-pattern', 'meta-category': 'stix2-pattern',
                               'Attribute': [self.version_attribute, attribute]}
                self.misp_event.add_object(**misp_object)

    def fetch_report(self):
        reports = []
        for o in self.event:
            if o._type == 'report':
                reports.append(o)
        if len(reports) == 1:
            self.report = reports[0]
            self.parse_report()

    def parse_external_galaxy(self, o):
        galaxy = {'name': galaxy_types[o._type]}
        if 'kill_chain_phases' in o:
            galaxy['type'] = o['kill_chain_phases'][0].get('phase_name')
        cluster = defaultdict(dict)
        cluster['value'] = o.get('name')
        cluster['description'] = o.get('description')
        if 'aliases' in o:
            aliases = []
            for a in o.get('aliases'):
                aliases.append(a)
            cluster['meta']['synonyms'] = aliases
        galaxy['GalaxyCluster'] = [cluster]
        self.misp_event['Galaxy'].append(galaxy)

    def parse_external_pattern(self, pattern):
        if ' OR ' in pattern and ' AND ' not in pattern:
            pattern = pattern.split('OR')
            for p in pattern:
                attribute = self.attribute_from_external_pattern(p)
                self.misp_event.add_attribute(**attribute)
        elif ' OR ' not in pattern and ' LIKE ' not in pattern:
            pattern = pattern.split('AND')
            if len(pattern) == 1:
                attribute = self.attribute_from_external_pattern(pattern[0])
                self.misp_event.add_attribute(**attribute)

    @staticmethod
    def attribute_from_external_pattern(pattern):
        pattern_type, pattern_value = pattern.split(' = ')
        pattern_type, pattern_value = pattern_type[1:].strip(), pattern_value[1:-2].strip()
        stix_type, value_type = pattern_type.split(':')
        if 'hashes' in value_type and 'x509' not in stix_type:
            h_type = value_type.split('.')[1]
            return {'type': h_type, 'value': pattern_value}
        else:
            # Might cause some issues, need more examples to test
            return {'type': external_pattern_mapping[stix_type][value_type].get('type'), 'value': pattern_value}

    def saveFile(self):
        eventDict = self.misp_event.to_json()
        outputfile = '{}.stix2'.format(self.filename)
        with open(outputfile, 'w') as f:
            f.write(eventDict)

    @staticmethod
    def getTimestampfromDate(stix_date):
        try:
            return int(stix_date.timestamp())
        except:
            return int(time.mktime(time.strptime(stix_date.split('+')[0], "%Y-%m-%d %H:%M:%S")))

    @staticmethod
    def get_misp_type(labels):
        return labels[0].split('=')[1][1:-1]

    @staticmethod
    def get_misp_category(labels):
        return labels[1].split('=')[1][1:-1]

    @staticmethod
    def parse_observable(observable, attribute_type):
        return misp_types_mapping[attribute_type](observable, attribute_type)

    @staticmethod
    def parse_pattern(pattern):
        if ' AND ' in pattern:
            pattern_parts = pattern.split(' AND ')
            if len(pattern_parts) == 3:
                _, value1 = pattern_parts[2].split(' = ')
                _, value2 = pattern_parts[0].split(' = ')
                return '{}|{}'.format(value1[1:-2], value2[1:-1])
            else:
                _, value1 = pattern_parts[0].split(' = ')
                _, value2 = pattern_parts[1].split(' = ')
                if value1 in ("'ipv4-addr'", "'ipv6-addr'"):
                    return value2[1:-2]
                return '{}|{}'.format(value1[1:-1], value2[1:-2])
        else:
            return pattern.split(' = ')[1][1:-2]

def main(args):
    pathname = os.path.dirname(args[0])
    stix_parser = StixParser()
    stix_parser.loadEvent(args, pathname)
    stix_parser.handler()
    stix_parser.saveFile()
    print(1)

if __name__ == "__main__":
    main(sys.argv)
